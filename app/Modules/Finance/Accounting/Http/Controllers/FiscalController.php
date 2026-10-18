<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinFiscalYear;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\FiscalYearService;
use App\Modules\Finance\Accounting\Services\PeriodService;
use App\Modules\Finance\Accounting\Services\YearEndService;
use App\Modules\Finance\Accounting\Support\FiscalPermissions;
use App\Support\Hubs\HubAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiscal-period & financial-year management. Tenant-scoped (each company runs its own fiscal
 * calendar). Segregation of duties is enforced against the user's accounting-module access tier
 * (see FiscalPermissions) — the same tier the hub navigation uses.
 */
class FiscalController extends Controller
{
    public function __construct(
        private FiscalYearService $years,
        private PeriodService $periods,
        private YearEndService $yearEnd,
    ) {
    }

    private function companyId(): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
    }

    private function tier(Request $request): int
    {
        return HubAccess::level($request->user(), 'accounting');
    }

    public function index(Request $request): Response
    {
        $companyId = $this->companyId();
        $this->years->ensureCurrentYear($companyId);

        $allYears = FinFiscalYear::when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->orderByDesc('start_date')->get();

        $selected = $request->integer('year')
            ? $allYears->firstWhere('id', $request->integer('year'))
            : ($allYears->firstWhere('is_current', true) ?? $allYears->first());

        $tier = $this->tier($request);

        $periods = $selected
            ? $selected->periods()->orderBy('period_no')->get()->map(fn (FinFiscalPeriod $p) => [
                'id' => $p->id, 'period_no' => $p->period_no, 'label' => $p->label,
                'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(),
                'status' => $p->status, 'status_color' => FinFiscalPeriod::STATUS_COLOR[$p->status] ?? 'grey',
                'actions' => $p->allowedActions(),
                'checks' => $this->periods->preCloseChecks($p),
            ])->values()
            : collect();

        return Inertia::render('Modules/Finance/Accounting/Periods', [
            'years'     => $allYears->map(fn ($y) => ['id' => $y->id, 'label' => $y->label, 'status' => $y->status,
                'start_date' => $y->start_date->toDateString(), 'end_date' => $y->end_date->toDateString(), 'is_current' => $y->is_current])->values(),
            'selected'  => $selected ? [
                'id' => $selected->id, 'label' => $selected->label, 'status' => $selected->status,
                'start_date' => $selected->start_date->toDateString(), 'end_date' => $selected->end_date->toDateString(),
                'is_current' => $selected->is_current, 'summary' => $this->years->summary($selected, $this->periods),
            ] : null,
            'periods'   => $periods,
            'perms'     => ['tier' => $tier, 'allowed' => FiscalPermissions::allowed($tier), 'matrix' => FiscalPermissions::matrix()],
            'fy_config' => [
                'start_month' => (int) (FinSetting::current($companyId)->fiscal_year_start_month ?? 1),
                'months'      => FiscalYearService::months(),
            ],
        ]);
    }

    public function updatePeriod(Request $request, FinFiscalPeriod $period): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:soft_close,hard_close,lock,reopen,unlock',
            'reason' => 'required_if:action,reopen,unlock|nullable|string|max:300',
        ]);

        if (! FiscalPermissions::can($this->tier($request), $data['action'], $period->status)) {
            abort(403, 'Your finance role may not perform this action.');
        }

        try {
            match ($data['action']) {
                'soft_close' => $this->periods->softClose($period, $data['reason'] ?? null),
                'hard_close' => $this->periods->hardClose($period, $data['reason'] ?? null),
                'lock'       => $this->periods->lock($period, $data['reason'] ?? null),
                'reopen'     => $this->periods->reopen($period, $data['reason'] ?? ''),
                'unlock'     => $this->periods->unlock($period, $data['reason'] ?? ''),
            };
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['period' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Period updated.');
    }

    public function checklist(Request $request, FinFiscalPeriod $period): JsonResponse
    {
        return response()->json(['checklist' => $this->periods->checklist($period)]);
    }

    public function toggleCheck(Request $request, FinFiscalPeriod $period): RedirectResponse
    {
        $data = $request->validate(['item' => 'required|string', 'done' => 'required|boolean']);
        try {
            $this->periods->toggleChecklistItem($period, $data['item'], (bool) $data['done']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['period' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Checklist updated.');
    }

    public function history(FinFiscalPeriod $period): JsonResponse
    {
        return response()->json(['history' => $this->periods->history($period)]);
    }

    // ── Financial years ──────────────────────────────────────────────────────

    public function createYear(Request $request): RedirectResponse
    {
        if (! FiscalPermissions::can($this->tier($request), 'manage_year')) {
            abort(403);
        }
        $data = $request->validate(['start_year' => 'nullable|integer|min:2000|max:2100']);
        $year = $data['start_year'] ?? null
            ? $this->years->createYear($this->companyId(), (int) $data['start_year'])
            : $this->years->createNextYear($this->companyId());

        return back()->with('success', "Financial year {$year->label} created.");
    }

    public function switchYear(Request $request, FinFiscalYear $year): RedirectResponse
    {
        if (! FiscalPermissions::can($this->tier($request), 'manage_year')) {
            abort(403);
        }
        $this->years->switchCurrent($year);

        return back()->with('success', "Switched to {$year->label}.");
    }

    public function updateYear(Request $request, FinFiscalYear $year): RedirectResponse
    {
        if (! FiscalPermissions::can($this->tier($request), 'manage_year')) {
            abort(403);
        }
        $data = $request->validate(['label' => 'required|string|max:60']);
        $this->years->updateLabel($year, $data['label']);

        return back()->with('success', 'Financial year updated.');
    }

    public function archiveYear(Request $request, FinFiscalYear $year): RedirectResponse
    {
        if (! FiscalPermissions::can($this->tier($request), 'manage_year')) {
            abort(403);
        }
        $this->years->archive($year);

        return back()->with('success', "{$year->label} archived.");
    }

    public function saveFyStart(Request $request): RedirectResponse
    {
        if (! FiscalPermissions::can($this->tier($request), 'settings')) {
            abort(403);
        }
        $data = $request->validate(['start_month' => 'required|integer|min:1|max:12']);
        $s = FinSetting::current($this->companyId());
        $s->fill(['company_id' => $this->companyId(), 'fiscal_year_start_month' => (int) $data['start_month']])->save();

        return back()->with('success', 'Fiscal year start month saved. It applies to newly created years.');
    }

    // ── Year-end wizard ──────────────────────────────────────────────────────

    public function yearEndSteps(FinFiscalYear $year): JsonResponse
    {
        return response()->json(['steps' => $this->yearEnd->stepsFor($year)]);
    }

    public function yearEndClose(Request $request, FinFiscalYear $year): RedirectResponse
    {
        if (! FiscalPermissions::can($this->tier($request), 'year_end')) {
            abort(403, 'Only a CFO or higher may run year-end.');
        }
        try {
            $r = $this->yearEnd->closeYear($year);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['period' => [$e->getMessage()]]);
        }

        $msg = $r['already_closed']
            ? "{$year->label} was already closed ({$r['journal_no']})."
            : ($r['journal_no']
                ? "{$year->label} closed — net " . ($r['net_profit'] >= 0 ? 'profit' : 'loss') . ' ' . number_format($r['net_profit'], 2) . " to retained earnings (journal {$r['journal_no']})."
                : "{$year->label} had no P&L to close.");

        return back()->with('success', $msg . " Next year {$r['next_year_label']} created.");
    }
}
