<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\OperationRequirement;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\RosterEntry;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\Workforce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workforce (MFG-016): the roster, who is qualified for what, and labour planned against needed
 * against worked.
 */
class WorkforceController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly Workforce $workforce) {}

    public function roster(Request $request): Response
    {
        $this->authorize('viewAny', RosterEntry::class);
        $start = Carbon::parse($request->validate(['week' => ['nullable', 'date']])['week'] ?? now()->toDateString())->startOfWeek();
        $end = $start->copy()->addDays(6);

        return Inertia::render('Modules/Manufacturing/Workforce/Roster', [
            'week' => $start->toDateString(),
            'days' => collect(range(0, 6))->map(fn ($d) => $start->copy()->addDays($d)->toDateString())->all(),
            'entries' => RosterEntry::query()->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->with(['shift:id,code,name', 'workCentre:id,code'])->get()->map(fn (RosterEntry $e) => [
                    'id' => $e->id, 'user_id' => $e->user_id, 'date' => $e->work_date->toDateString(), 'shift' => $e->shift?->code,
                    'work_centre' => $e->workCentre?->code, 'overtime_minutes' => (float) $e->overtime_minutes,
                ])->values(),
            'people' => $this->tenantPeopleOptions($request),
            'shifts' => Shift::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => "{$s->code} — {$s->name}"])->values(),
            'work_centres' => WorkCentre::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => "{$c->code} — {$c->name}"])->values(),
            'gaps' => collect($this->workforce->capacityGaps($start, $end))->where('gap', '>', 0)->values(),
            'can' => ['manage' => $request->user()->can('manage', RosterEntry::class)],
        ]);
    }

    public function storeRoster(Request $request): RedirectResponse
    {
        $this->authorize('manage', RosterEntry::class);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'shift_id' => ['required', 'integer', $this->tenantExists('mfg_shifts')],
            'work_centre_id' => ['nullable', 'integer', $this->tenantExists('mfg_work_centres')],
            'dates' => ['required', 'array', 'min:1'], 'dates.*' => ['date'],
            'overtime_minutes' => ['nullable', 'numeric', 'min:0', 'max:720'],
        ]);

        foreach ($data['dates'] as $date) {
            RosterEntry::query()->updateOrCreate(
                ['user_id' => $data['user_id'], 'shift_id' => $data['shift_id'], 'work_date' => Carbon::parse($date)->toDateString()],
                ['company_id' => $this->tenantId($request), 'work_centre_id' => $data['work_centre_id'] ?? null,
                    'overtime_minutes' => $data['overtime_minutes'] ?? 0, 'created_by' => $request->user()->id]);
        }

        return back()->with('success', 'Rostered.');
    }

    public function destroyRoster(Request $request, int $id): RedirectResponse
    {
        $this->authorize('manage', RosterEntry::class);
        RosterEntry::query()->findOrFail($id)->delete();

        return back()->with('success', 'Removed from the roster.');
    }

    // ── Qualifications ───────────────────────────────────────────────────────────────────────

    public function qualifications(Request $request): Response
    {
        $this->authorize('qualifications', RosterEntry::class);
        $opUuid = $request->validate(['operation' => ['nullable', 'string']])['operation'] ?? null;
        $op = $opUuid ? Op::query()->where('uuid', $opUuid)->with('order:id,number')->first() : null;
        $people = \App\Core\User\Models\User::query()->with('person')->whereIn('id', array_column($this->tenantPeople($request), 'id'))->get();

        return Inertia::render('Modules/Manufacturing/Workforce/Qualifications', [
            'requirements' => OperationRequirement::query()->with(['routingOperation.routing.item.stockItem:id,code', 'workCentre:id,code'])->get()
                ->map(fn (OperationRequirement $r) => [
                    'id' => $r->uuid, 'kind' => $r->kind, 'label' => $this->workforce->label($r), 'is_mandatory' => (bool) $r->is_mandatory,
                    'applies_to' => $r->workCentre ? "Work centre {$r->workCentre->code}"
                        : trim(($r->routingOperation?->routing?->item?->stockItem?->code ?? '').' · '.($r->routingOperation ? "{$r->routingOperation->sequence} {$r->routingOperation->name}" : '')),
                ])->values(),
            'training' => $this->workforce->trainingNeeds(),
            'candidates' => $op ? ['operation' => "{$op->order?->number} · {$op->sequence} {$op->name}", 'people' => $this->workforce->candidates($op, $people)] : null,
            'open_operations' => Op::query()->whereNotIn('status', [Op::COMPLETED, Op::SKIPPED])
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['RELEASED', 'IN_PRODUCTION']))->with('order:id,number')->limit(200)->get()
                ->map(fn ($o) => ['id' => $o->uuid, 'name' => "{$o->order?->number} · {$o->sequence} {$o->name}"])->values(),
            'options' => $request->user()->can('manage', RosterEntry::class) ? [
                'skills' => DB::table('skills')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'certifications' => DB::table('lms_certifications')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'competencies' => DB::table('sheq_competencies')->whereNull('deleted_at')->where('is_active', true)->orderBy('title')->get(['id', 'title as name']),
                'operations' => RoutingOperation::query()->join('mfg_routings as r', 'r.id', '=', 'mfg_routing_operations.routing_id')
                    ->join('mfg_items as i', 'i.id', '=', 'r.item_id')->join('fin_stock_items as s', 's.id', '=', 'i.stock_item_id')
                    ->whereIn('r.status', ['DRAFT', 'APPROVED'])->orderBy('s.code')->orderBy('mfg_routing_operations.sequence')
                    ->get(['mfg_routing_operations.id', 'mfg_routing_operations.sequence', 'mfg_routing_operations.name', 's.code', 'r.revision'])
                    ->map(fn ($o) => ['id' => $o->id, 'name' => "{$o->code} rev {$o->revision} · {$o->sequence} {$o->name}"])->values(),
                'work_centres' => WorkCentre::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => "{$c->code} — {$c->name}"])->values(),
            ] : null,
            'can' => ['manage' => $request->user()->can('manage', RosterEntry::class)],
        ]);
    }

    public function storeRequirement(Request $request): RedirectResponse
    {
        $this->authorize('manage', RosterEntry::class);
        $data = $request->validate([
            'kind' => ['required', Rule::in(OperationRequirement::KINDS)],
            'reference_id' => ['required', 'integer'],
            'routing_operation_id' => ['nullable', 'required_without:work_centre_id', 'integer', $this->tenantExists('mfg_routing_operations')],
            'work_centre_id' => ['nullable', 'integer', $this->tenantExists('mfg_work_centres')],
            'min_level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'is_mandatory' => ['boolean'],
        ]);
        $table = ['SKILL' => 'skills', 'CERTIFICATION' => 'lms_certifications', 'COMPETENCY' => 'sheq_competencies'][$data['kind']];
        abort_unless(DB::table($table)->where('id', $data['reference_id'])->exists(), 422, 'Unknown qualification.');

        OperationRequirement::create($data + ['company_id' => $this->tenantId($request), 'is_mandatory' => $data['is_mandatory'] ?? true]);

        return back()->with('success', 'Requirement added.');
    }

    public function destroyRequirement(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', RosterEntry::class);
        OperationRequirement::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', 'Requirement removed.');
    }

    // ── Labour ───────────────────────────────────────────────────────────────────────────────

    public function labour(Request $request): Response
    {
        $this->authorize('labour', RosterEntry::class);
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = Carbon::parse($f['from'] ?? now()->subDays(13)->toDateString());
        $to = Carbon::parse($f['to'] ?? now()->toDateString());

        return Inertia::render('Modules/Manufacturing/Workforce/Labour', [
            'utilisation' => $this->workforce->utilisation($from, $to),
            'planned_vs_actual' => $this->workforce->plannedVsActual($from, $to),
            'gaps' => $this->workforce->capacityGaps(now()->startOfDay(), now()->addDays(13)),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /** Hours worked per person per day, as a CSV for costing and for payroll to import as it sees fit. */
    public function hours(Request $request): StreamedResponse
    {
        $this->authorize('labour', RosterEntry::class);
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $rows = $this->workforce->hours(Carbon::parse($f['from'] ?? now()->subDays(13)->toDateString()), Carbon::parse($f['to'] ?? now()->toDateString()));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'person', 'hours']);
            foreach ($rows as $r) {
                // A leading = + - @ would be read as a formula by a spreadsheet.
                fputcsv($out, [$r['date'], preg_replace('/^([=+\-@])/', "'$1", $r['name']), $r['hours']]);
            }
            fclose($out);
        }, 'production-hours.csv', ['Content-Type' => 'text/csv']);
    }

    private function tenantId(Request $request): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
    }
}
