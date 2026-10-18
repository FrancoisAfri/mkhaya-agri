<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Enums\PayFrequency;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollApproval;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollAnomalyService;
use App\Modules\HR\Payroll\Services\PayrollPolicyGuard;
use App\Modules\HR\Payroll\Services\OvertimeCalculator;
use App\Modules\HR\Payroll\Services\PayrollApprovalService;
use App\Modules\HR\Payroll\Services\PayrollService;
use App\Modules\HR\Payroll\Services\RunValidationService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollRunController extends Controller
{
    public function __construct(
        private readonly PayrollService        $payrollService,
        private readonly PayrollAnomalyService $anomalyService,
        private readonly PayrollPolicyGuard    $policyGuard,
        private readonly PayrollApprovalService $approvals,
        private readonly RunValidationService  $validation,
    ) {}

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $runs = PayrollRun::withCount('payslips')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('month'), fn ($q, $m) => $q->whereRaw(
                "TO_CHAR(period_start, 'YYYY-MM') = ?", [$m]
            ))
            ->latest('period_start')
            ->paginate(20)
            ->withQueryString();

        $now   = now();
        $stats = [
            'total_runs'        => PayrollRun::count(),
            'total_released'    => PayrollRun::where('status', PayrollRunStatus::Released->value)->count(),
            'total_draft'       => PayrollRun::where('status', PayrollRunStatus::Draft->value)->count(),
            'current_month_net' => (float) PayrollRun::where('status', PayrollRunStatus::Released->value)
                ->whereYear('period_start', $now->year)
                ->whereMonth('period_start', $now->month)
                ->sum('total_net'),
        ];

        return Inertia::render('Modules/HR/Payroll/Runs/Index', [
            'runs'    => $runs,
            'filters' => $request->only(['status', 'month']),
            'stats'   => $stats,
        ]);
    }

    // ── Create / Store ────────────────────────────────────────────────────────

    public function create(): Response
    {
        // Each cycle offers the period it is currently in, so choosing "weekly" fills in this week
        // rather than leaving the clerk to work out which Monday it started on. The dates stay
        // editable — a run for last month is an ordinary thing to create.
        $today = now();
        $periods = [];
        foreach (PayFrequency::cases() as $frequency) {
            [$start, $end] = $frequency->periodFor($today);
            $periods[$frequency->value] = [
                'period_start' => $start->toDateString(),
                'period_end'   => $end->toDateString(),
                'headcount'    => $this->headcountOn($frequency->value),
            ];
        }

        return Inertia::render('Modules/HR/Payroll/Runs/Create', [
            'periods'          => $periods,
            'defaultFrequency' => $this->payrollService->payFrequency(),
        ]);
    }

    /** How many active employees are paid on a cycle — shown so an empty run is not a surprise. */
    private function headcountOn(string $frequency): int
    {
        return (int) DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', 1)
            ->where(function ($q) use ($frequency) {
                $q->where('p.pay_frequency', $frequency);
                if ($frequency === $this->payrollService->payFrequency()) {
                    $q->orWhereNull('p.pay_frequency');
                }
            })
            ->count();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:200',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after:period_start',
            'frequency'    => 'nullable|string|in:weekly,fortnightly,monthly',
            'notes'        => 'nullable|string|max:2000',
        ]);

        $run = $this->payrollService->createPayrollRun($validated, auth()->id());

        return redirect()->route('payroll.runs.show', $run->uuid)
            ->with('success', 'Payroll run created. Now add employees and calculate.');
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function show(PayrollRun $run): Response
    {
        $run->load([
            'payslips',
            'anomalies' => fn ($q) => $q->latest()->limit(20),
            'approvals.steps.approver',
            'changeLogs' => fn ($q) => $q->latest()->limit(50),
            'auditLogs'  => fn ($q) => $q->latest()->limit(50),
        ]);

        $payslipsWithEmployees = $run->payslips->map(function ($payslip) {
            // Job title lives in hr_positions (via position_id); fall back to the free-text
            // position column. The plain p.position column is usually empty.
            $employee = DB::table('users as u')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
                ->leftJoin('hr_positions as hp', 'hp.id', '=', 'p.position_id')
                ->where('u.id', $payslip->employee_id)
                ->selectRaw("u.id, COALESCE(TRIM(CONCAT(p.first_name, ' ', COALESCE(p.surname, ''))), u.email) AS name, NULLIF(hp.name, '') AS position")
                ->first();

            return array_merge($payslip->toArray(), [
                'employee_name'  => $employee?->name ?? 'Unknown',
                'employee_title' => $employee?->position ?? '',
            ]);
        });

        // Employees not yet on this run (for the add dialog)
        $existingIds = $run->payslips->pluck('employee_id')->toArray();
        // Only the people this run can actually pay. A run covers one pay cycle, so offering the
        // weekly wage staff on the monthly run is offering a mistake — and one that reads as
        // perfectly ordinary until the same weeks are paid twice.
        $runFrequency = \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::canonicalFrequency($run->frequency)
            ?? $this->payrollService->payFrequency();

        $availableEmployees = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('hr_positions as hp', 'hp.id', '=', 'p.position_id')
            ->whereNotIn('u.id', $existingIds)
            ->where('u.status', 1)
            // Somebody with nothing captured is on the company default, which is what this run is
            // on whenever the run itself does not say otherwise.
            ->where(function ($q) use ($runFrequency) {
                $q->where('p.pay_frequency', $runFrequency);
                if ($runFrequency === $this->payrollService->payFrequency()) {
                    $q->orWhereNull('p.pay_frequency');
                }
            })
            ->selectRaw("u.id, COALESCE(TRIM(CONCAT(p.first_name, ' ', COALESCE(p.surname, ''))), u.email) AS name, NULLIF(hp.name, '') AS job_title, COALESCE(p.monthly_package, 0) AS basic_salary")
            ->orderByRaw("name")
            ->get();

        // Employer-side costs (what Finance needs to see the TRUE payroll cost).
        // Employer UIF matches the employee 1% (same capped base); SDL is 1% of the
        // leviable payroll (payable when the company's annual payroll exceeds R500k).
        $totalGross  = (float) $run->total_gross;
        $employerUif = (float) $run->payslips->sum('uif');
        $sdl         = round($totalGross * 0.01, 2);
        $employerPension = (float) DB::table('payroll_earnings_lines')
            ->whereIn('payslip_id', $run->payslips->pluck('id'))
            ->where('line_type', 'employer_pension')
            ->sum('amount');
        $employerMedical = (float) DB::table('payroll_earnings_lines')
            ->whereIn('payslip_id', $run->payslips->pluck('id'))
            ->where('line_type', 'employer_medical')
            ->sum('amount');
        $employerCosts = [
            'gross'               => $totalGross,
            'employer_uif'        => $employerUif,
            'sdl'                 => $sdl,
            'employer_pension'    => $employerPension,
            'employer_medical'    => $employerMedical,
            'total_employer_cost' => round($totalGross + $employerUif + $sdl + $employerPension + $employerMedical, 2),
        ];

        return Inertia::render('Modules/HR/Payroll/Runs/Show', [
            'run'                => $run,
            'payslips'           => $payslipsWithEmployees,
            'anomalies'          => $run->anomalies,
            'approval'           => $run->approvals->first(),
            'changeLogs'         => $run->changeLogs,
            'auditLogs'          => $run->auditLogs,
            'availableEmployees' => $availableEmployees,
            'employerCosts'      => $employerCosts,
            'leaveActive'        => app(\App\Modules\HR\Payroll\Services\LeavePayrollService::class)->leaveAvailable(),
            // Everything wrong with the run, before anybody releases it. On the screen rather than
            // behind a button: a check nobody runs is a check that does not exist, and each of
            // these otherwise gets discovered by the employee on payday.
            'validation'         => $run->payslips->isNotEmpty() ? $this->validation->validate($run) : null,
        ]);
    }

    // ── Add employees ─────────────────────────────────────────────────────────

    public function addEmployees(Request $request, PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot modify a released or cancelled run.');

        $request->validate([
            'employee_ids'   => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:users,id',
        ]);

        $result = $this->payrollService->generatePayrollItems($run, $request->employee_ids);
        // Auto-calculate so gross/PAYE/UIF/net populate immediately on add.
        $this->payrollService->calculateAllPayslips($run, auth()->id());

        // Anyone on another cycle is named rather than dropped quietly — a count that does not match
        // what was selected is the kind of thing a payroll clerk notices on payday, not before it.
        if ($result['mismatched'] !== []) {
            return back()->with('warning', sprintf(
                '%d employee(s) added and calculated. Not added — not paid on this run\'s cycle: %s.',
                $result['added'], implode(', ', $result['mismatched']),
            ));
        }

        return back()->with('success', $result['added'] . ' employee(s) added and calculated.');
    }

    // ── Calculate all payslips ────────────────────────────────────────────────

    public function calculateAll(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot recalculate a released or cancelled run.');
        abort_if($run->payslips()->count() === 0, 422, 'Add employees before calculating.');

        $count = $this->payrollService->calculateAllPayslips($run, auth()->id());

        return back()->with('success', "Calculated payslips for {$count} employee(s). Totals updated.");
    }

    /**
     * Work out overtime from attendance, at the statutory multipliers.
     *
     * A separate action rather than part of calculating, because it changes what people are paid.
     * It is idempotent — pressing it again after correcting a timesheet corrects the pay rather than
     * doubling it — so a preview then an apply is a safe way to work.
     */
    public function applyOvertime(Request $request, PayrollRun $run, OvertimeCalculator $overtime): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot change a released or cancelled run.');
        abort_if($run->payslips()->count() === 0, 422, 'Add employees before working out overtime.');

        $result = $overtime->applyToRun($run);

        if ($result['payslips'] === 0) {
            return back()->with('success', 'No overtime, Sunday or public holiday hours were captured for this period.');
        }

        // Recalculate, or the run's totals still describe the payroll before the overtime went on.
        $this->payrollService->calculateAllPayslips($run, auth()->id());

        $this->payrollService->audit($run, auth()->id(), 'run.overtime_applied',
            sprintf('Overtime worked out from attendance for %d employee(s), R%s.',
                $result['payslips'], number_format($result['total'], 2)),
            $result);

        return back()->with('success', sprintf(
            'Overtime applied for %d employee(s) — R%s across %d line(s). Totals recalculated.',
            $result['payslips'], number_format($result['total'], 2), $result['lines'],
        ));
    }

    // ── Remove an employee from the run ───────────────────────────────────────

    public function removeEmployee(PayrollRun $run, int $payslip): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot modify a released or cancelled run.');

        $slip = $run->payslips()->whereKey($payslip)->firstOrFail();
        DB::table('payroll_earnings_lines')->where('payslip_id', $slip->id)->delete();
        // Hard-delete: a payslip removed from a draft run should be gone completely, so the
        // same employee can be re-added (a soft-deleted row would block re-add on the unique key).
        $slip->forceDelete();

        // Recompute run totals from whatever remains (handles the empty case too).
        $this->payrollService->updateRunTotals($run);

        return back()->with('success', 'Employee removed from the run.');
    }

    // ── Bulk add an earnings/deduction line to many payslips at once ──────────

    public function bulkAddLine(Request $request, PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot modify a released or cancelled run.');

        $types = array_column(\App\Modules\HR\Payroll\Enums\EarningsLineType::cases(), 'value');
        $data = $request->validate([
            'line_type'      => 'required|in:' . implode(',', $types),
            'description'    => 'required|string|max:200',
            'amount'         => 'required|numeric',
            'employee_ids'   => 'nullable|array',       // empty = apply to every employee on the run
            'employee_ids.*' => 'integer',
        ]);

        $query = $run->payslips();
        if (! empty($data['employee_ids'])) {
            $query->whereIn('employee_id', $data['employee_ids']);
        }
        $payslipIds = $query->pluck('id');
        abort_if($payslipIds->isEmpty(), 422, 'No matching employees on this run.');

        // One bulk insert (fast for hundreds/thousands of employees), stamped with the
        // run's company_id so it satisfies row-level security.
        $now = now();
        $rows = $payslipIds->map(fn ($id) => [
            'payslip_id'  => $id,
            'company_id'  => $run->company_id,
            'line_type'   => $data['line_type'],
            'description' => $data['description'],
            'amount'      => $data['amount'],
            'quantity'    => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->all();
        DB::table('payroll_earnings_lines')->insert($rows);

        $this->payrollService->calculateAllPayslips($run, auth()->id());

        return back()->with('success', count($rows) . ' employee(s) updated and the run recalculated.');
    }

    // ── Sync leave from the Leave module (unpaid leave + pay-outs) ────────────

    public function syncLeave(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot modify a released or cancelled run.');

        $result = app(\App\Modules\HR\Payroll\Services\LeavePayrollService::class)->syncLeaveForRun($run);
        if (! $result['available']) {
            return back()->with('warning', 'The Leave module is not active for this company.');
        }

        return back()->with('success', "Leave synced — {$result['synced']} line(s) applied and the run recalculated.");
    }

    // ── Refresh salaries from HR profiles, then recalculate ───────────────────

    public function refreshSalaries(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'Cannot modify a released or cancelled run.');
        abort_if($run->payslips()->count() === 0, 422, 'Add employees before refreshing salaries.');

        $updated = $this->payrollService->refreshBasicSalaries($run);
        $this->payrollService->calculateAllPayslips($run, auth()->id());

        return back()->with('success', "Refreshed {$updated} salary/salaries from employee profiles and recalculated.");
    }

    // ── Lock ─────────────────────────────────────────────────────────────────

    public function lock(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'This payroll run cannot be locked.');

        $this->payrollService->lockPayrollRun($run, auth()->id());

        return back()->with('success', 'Payroll run locked for review.');
    }

    // ── Submit for approval ───────────────────────────────────────────────────

    public function submit(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status === PayrollRunStatus::Released,  403, 'Run is already released.');
        abort_if($run->status === PayrollRunStatus::Cancelled, 403, 'Run has been cancelled.');

        // Policy check — may throw ValidationException (block) or return warnings
        $this->policyGuard->forPayrollRunSubmitted($run, auth()->id());

        // High-risk guard
        if (in_array($run->ai_risk_level, ['high', 'critical'])) {
            $this->policyGuard->forHighRiskPayroll($run, auth()->id());
        }

        // Delegated rather than duplicated. This method used to build the approval itself and set
        // `current_level` to the integer 1 — but that column is a varchar constrained to
        // payroll_officer | hr_manager | finance | executive, so every submission from this screen
        // died on a check-constraint violation and returned a 500. Nobody could submit a run for
        // approval at all.
        //
        // The service has always done it correctly, and does more besides: it starts at the right
        // level, escalates straight to executive when there are enough critical anomalies, records
        // the block reasons, and returns the existing approval instead of refusing when one is
        // already open.
        $approval = $this->approvals->submitPayrollForReview($run);

        abort_if(
            $approval->wasRecentlyCreated === false,
            422,
            'This run already has a pending approval.',
        );

        $run->update(['status' => PayrollRunStatus::Review->value]);
        $this->payrollService->audit($run, auth()->id(), 'run.submitted', 'Run submitted for approval.');

        return back()->with('success', 'Payroll run submitted for approval.');
    }

    // ── Release ───────────────────────────────────────────────────────────────

    public function release(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status === PayrollRunStatus::Released,  422, 'Run is already released.');
        abort_if($run->status === PayrollRunStatus::Cancelled, 403, 'Cancelled runs cannot be released.');

        /*
         * Nothing that would produce a demonstrably wrong payment gets past here.
         *
         * Releasing is the irreversible step: it locks the payslips, emails them, posts the journal
         * and charges the budget. A blocker found afterwards is found by the employee. Warnings do
         * not stop a release on purpose — a missing tax number is a problem for February's IRP5 and
         * no reason to stop paying somebody in March, and refusing over one teaches people to hunt
         * for the override until the blockers stop working too.
         */
        $report = $this->validation->validate($run);

        if ($report['blockers'] > 0) {
            $first = collect($report['findings'])->firstWhere('severity', RunValidationService::BLOCKER);

            throw ValidationException::withMessages([
                'release' => sprintf(
                    '%d problem(s) would produce a wrong payment, so this run cannot be released. First: %s',
                    $report['blockers'],
                    $first['message'] ?? '',
                ),
            ]);
        }

        // Policy check before release
        $this->policyGuard->forPayrollReleased($run, auth()->id());

        $this->payrollService->releasePayrollRun($run, auth()->id());

        return back()->with('success', $report['warnings'] > 0
            ? "Payroll run released. {$report['warnings']} warning(s) remain on the validation report."
            : 'Payroll run released successfully.');
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function cancel(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status === PayrollRunStatus::Released, 403, 'Released runs cannot be cancelled.');

        $run->update(['status' => PayrollRunStatus::Cancelled->value]);
        $this->payrollService->audit($run, auth()->id(), 'run.cancelled', 'Run cancelled.');

        return back()->with('success', 'Payroll run cancelled.');
    }

    // ── Anomaly check ─────────────────────────────────────────────────────────

    public function runAnomalyCheck(PayrollRun $run): RedirectResponse
    {
        $count = $this->anomalyService->runFullAnomalyCheck($run);

        return back()->with('success', "Anomaly check complete. {$count} anomaly(ies) detected.");
    }
}
