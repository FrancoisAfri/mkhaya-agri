<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\ContinuousMonitor;
use App\Modules\SupplierManagement\DueDiligence\Services\MonitoringReport;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierChangeEvent;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monitoring inbox: everything across every supplier that somebody has not yet looked at.
 *
 * Monitoring produces work, and work needs somewhere to go. Without this screen every alert lives on
 * the supplier it belongs to, which means finding them requires already knowing which suppliers to
 * open — so the alerts that matter are the ones on suppliers nobody thought to check.
 *
 * Three queues in one place because they are one job: a change on the registry, a story in the
 * press, and a name off a sanctions list are all "something happened, decide what it means". Keeping
 * them on separate screens produces three half-worked queues.
 *
 * Ordered worst-first and oldest-first within that, because the failure mode of any queue is the
 * item that quietly ages at the bottom.
 */
class SupplierMonitoringController extends Controller
{
    public function __construct(
        private readonly ContinuousMonitor $monitor,
        private readonly RiskEngine $risk,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $severity = $request->string('severity')->toString() ?: null;

        return Inertia::render('Modules/SupplierManagement/Monitoring/Inbox', [
            'changes' => $this->changes($severity),
            'findings' => $this->findings($severity),
            'hits' => $this->hits(),
            'summary' => $this->summary(),
            'due' => $this->due(),
            'cases' => $this->openCases(),
            'filters' => ['severity' => $severity],
        ]);
    }

    /** The monthly report, as a screen rather than a mail nobody opens. */
    public function report(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $month = $request->string('month')->toString();
        $from = $month ? Carbon::parse($month.'-01')->startOfMonth() : Carbon::now()->startOfMonth();

        return Inertia::render('Modules/SupplierManagement/Monitoring/Report', [
            'report' => app(MonitoringReport::class)->forPeriod($from, $from->copy()->endOfMonth()),
            'month' => $from->format('Y-m'),
            // Enough months to see a trend, not so many the picker becomes a scroll.
            'months' => collect(range(0, 11))
                ->map(fn ($n) => Carbon::now()->subMonths($n)->format('Y-m'))
                ->all(),
        ]);
    }

    /** Record a decision on a detected change. */
    public function reviewChange(Request $request, SupplierChangeEvent $change): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                SupplierChangeEvent::STATUS_ACKNOWLEDGED,
                SupplierChangeEvent::STATUS_ACTIONED,
                SupplierChangeEvent::STATUS_DISMISSED,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // Dismissing needs a reason, for the same reason clearing a screening match does: a year
        // from now nobody can tell an unexplained dismissal from a mis-click.
        if ($data['status'] === SupplierChangeEvent::STATUS_DISMISSED && trim((string) ($data['note'] ?? '')) === '') {
            return back()->withErrors(['note' => __('supplier_management.monitoring.errors.reason_required')]);
        }

        $change->review($data['status'], Auth::id(), $data['note'] ?? null);

        return back()->with('success', __('supplier_management.monitoring.change_reviewed'));
    }

    /** Sweep one supplier now rather than waiting for tonight. */
    public function sweep(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $run = $this->monitor->sweep($supplier, 'manual', $request->boolean('force'));
        $this->risk->assess($supplier->fresh(), 'monitoring');

        if ($run->sources_queried === 0 && $run->sources_unavailable > 0) {
            return back()->with('warning', __('supplier_management.monitoring.no_sources'));
        }

        return back()->with(
            $run->findings_new > 0 ? 'warning' : 'success',
            __('supplier_management.monitoring.swept', [
                'checks' => $run->sources_queried,
                'changes' => $run->findings_new,
            ])
        );
    }

    private function changes(?string $severity): array
    {
        return SupplierChangeEvent::query()
            ->unreviewed()
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->with('supplier:id,company_name,risk_band')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('detected_at')
            ->limit(100)
            ->get()
            ->map(fn (SupplierChangeEvent $c) => [
                'id' => $c->id,
                'supplier_id' => $c->supplier_id,
                'supplier' => $c->supplier->company_name ?? '—',
                'risk_band' => $c->supplier->risk_band ?? null,
                'category' => $c->categoryLabel(),
                'severity' => $c->severity,
                'summary' => $c->summary,
                'previous' => $c->previous_value,
                'current' => $c->current_value,
                'detected_at' => $c->detected_at?->toDateTimeString(),
                'detected_by' => $c->detected_by,
                'age_days' => $c->detected_at ? (int) $c->detected_at->diffInDays(now()) : null,
                'risk_movement' => $c->riskMovement(),
            ])
            ->all();
    }

    private function findings(?string $severity): array
    {
        return SupplierFinding::query()
            ->unadjudicated()
            ->negative()
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->with('supplier:id,company_name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('first_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (SupplierFinding $f) => [
                'id' => $f->id,
                'supplier_id' => $f->supplier_id,
                'supplier' => $f->supplier->company_name ?? '—',
                'title' => $f->title,
                'category' => $f->categoryLabel(),
                'severity' => $f->severity,
                'source' => $f->source_name ?: $f->platform,
                'url' => $f->url,
                'published_at' => $f->published_at?->toDateString(),
                'entity_confidence' => $f->entity_confidence,
                'status' => $f->status,
            ])
            ->all();
    }

    private function hits(): array
    {
        return ScreeningHit::query()
            ->unadjudicated()
            ->with('supplier:id,company_name')
            ->orderByRaw("CASE list_type WHEN 'sanctions' THEN 0 ELSE 1 END")
            ->orderByDesc('match_score')
            ->limit(100)
            ->get()
            ->map(fn (ScreeningHit $h) => [
                'id' => $h->id,
                'supplier_id' => $h->supplier_id,
                'supplier' => $h->supplier->company_name ?? '—',
                'subject' => $h->subject_name,
                'list_type' => $h->list_type,
                'list_name' => $h->list_name,
                'matched_name' => $h->matched_name,
                'match_score' => $h->match_score,
                'first_seen' => $h->first_seen_at?->toDateString(),
            ])
            ->all();
    }

    private function summary(): array
    {
        $changes = SupplierChangeEvent::query()->unreviewed()->get();

        return [
            'changes' => $changes->count(),
            'changes_serious' => $changes->whereIn('severity', ['high', 'critical'])->count(),
            'findings' => SupplierFinding::query()->unadjudicated()->negative()->count(),
            'hits' => ScreeningHit::query()->unadjudicated()->count(),
            // The oldest thing nobody has looked at. The failure mode of a queue is the item that
            // quietly ages at the bottom, so it gets its own number.
            'oldest_days' => $changes->min('detected_at')
                ? (int) Carbon::parse($changes->min('detected_at'))->diffInDays(now())
                : null,
        ];
    }

    /**
     * The cases waiting on somebody.
     *
     * A case was reachable only from the passport of the supplier it belonged to, which means it
     * could be found only by somebody who already knew to look at that supplier — and the whole
     * point of opening one is to reach somebody who does not. Verification at registration opens
     * cases automatically now, so without a queue the first a buyer would hear of a failed check is
     * when they opened a record for an unrelated reason.
     *
     * Overdue first, then oldest, because a case's age is the thing that makes it urgent.
     *
     * @return list<array<string, mixed>>
     */
    private function openCases(): array
    {
        return DueDiligenceCase::query()
            ->open()
            ->with('supplier:id,uuid,company_name,risk_band')
            ->orderByRaw('due_at IS NULL, due_at')
            ->orderBy('opened_at')
            ->limit(50)
            ->get()
            ->map(fn (DueDiligenceCase $case) => [
                'id' => $case->id,
                'reference' => $case->reference,
                'state' => $case->state,
                'tier' => $case->tier,
                'risk_band' => $case->risk_band,
                'opened_at' => $case->opened_at?->toDateString(),
                'due_at' => $case->due_at?->toDateString(),
                // A date alone makes the reader do the arithmetic, and the arithmetic is the point.
                'overdue' => $case->due_at !== null && $case->due_at->isPast(),
                'supplier' => $case->supplier ? [
                    'uuid' => $case->supplier->uuid,
                    'name' => $case->supplier->company_name,
                    'risk_band' => $case->supplier->risk_band,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /** Suppliers whose checks have gone stale — the work nobody has been alerted to yet. */
    private function due(): array
    {
        return Supplier::query()
            ->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('monitoring_due_at')->orWhere('monitoring_due_at', '<', now()))
            ->orderByRaw("CASE risk_band WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->limit(25)
            ->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                // The screen links to the passport, which is addressed by uuid.
                'uuid' => $s->uuid,
                'name' => $s->company_name,
                'risk_band' => $s->risk_band,
                'last_monitored_at' => $s->last_monitored_at?->toDateString(),
                'never' => $s->last_monitored_at === null,
                'due_checks' => count($this->monitor->dueChecks($s)),
            ])
            ->all();
    }
}
