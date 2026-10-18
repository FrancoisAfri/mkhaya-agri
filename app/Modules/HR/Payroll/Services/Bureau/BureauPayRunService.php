<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Support\PayPeriod;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRunEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayslip;
use App\Modules\HR\Payroll\Services\SecurePayslipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drives a bureau pay run: generates a payslip per active employee, calculates
 * earnings/deductions through the SA tax engine, and finalises into locked,
 * encrypted payslip PDFs. Company-level SDL is settled once the run is summed.
 */
class BureauPayRunService
{
    public function __construct(
        private readonly PayrollTaxEngine $tax,
        private readonly SecurePayslipService $secure,
        private readonly BureauEmployeeService $employees,
        private readonly EtiClaimService $etiClaims,
        private readonly BureauAuditService $audit,
        private readonly PayrollValidationService $validation,
        private readonly BureauApprovalService $approvals,
    ) {
    }

    public function create(BureauCompany $company, array $data): BureauPayRun
    {
        foreach (['period_start', 'period_end'] as $req) {
            if (blank($data[$req] ?? null)) {
                throw ValidationException::withMessages([$req => 'This field is required.']);
            }
        }
        $taxYear = $data['tax_year'] ?? $company->tax_year ?? (int) now()->addMonths(10)->format('Y');

        $runType = $data['run_type'] ?? 'regular';
        if (! in_array($runType, ['regular', 'offcycle', 'bonus', 'termination'], true)) {
            $runType = 'regular';
        }

        // Chosen, not inherited. It used to be copied from the company unconditionally, which meant
        // a client could only ever have runs on one cycle — and because process() then filters
        // employees to the run's cycle, anybody on a different one was excluded from every run that
        // could exist and was never paid at all. Silently: no error, no warning, no empty payslip.
        $frequency = PayrollTaxEngine::canonicalFrequency($data['frequency'] ?? null)
            ?? PayrollTaxEngine::canonicalFrequency($company->pay_frequency)
            ?? 'monthly';

        // Two runs of the same cycle covering the same days is how somebody gets paid twice. The
        // check is here rather than only at the database, because the officer needs to be told which
        // run already covers it, not handed a constraint violation.
        // An off-cycle run is by definition an extra payment inside a period already run, so it is
        // exempt — both as the run being created and as one already on file.
        if ($runType === 'regular') {
            $this->assertNoOverlappingRun($company, $frequency, $data['period_start'], $data['period_end']);
        }

        $run = $company->payRuns()->create([
            'name'         => $data['name'] ?? PayPeriod::label($frequency, $data['period_start'], $data['period_end']),
            'frequency'    => $frequency,
            'run_type'     => $runType,
            'period_start' => $data['period_start'],
            'period_end'   => $data['period_end'],
            'pay_date'     => $data['pay_date'] ?? null,
            'tax_year'     => $taxYear,
            'status'       => BureauPayRun::STATUS_DRAFT,
            'created_by'   => auth()->id(),
        ]);

        // A regular run means everybody on that cycle, so its roster starts full. Every other kind
        // is an exception by definition — a bonus, a termination settlement, an off-cycle payment —
        // and starts empty so the officer says who it is for. Sweeping the whole payroll into a
        // bonus run is how three people's bonus becomes everybody's.
        $seeded = $runType === 'regular' ? $this->seedRoster($run) : 0;

        $this->audit->run($run, BureauAuditService::RUN_CREATED, "Pay run {$run->name} created for {$company->name}.", [
            'run_type'  => $runType,
            'pay_date'  => (string) $run->pay_date,
            'employees' => $seeded,
        ]);

        return $run;
    }

    /**
     * Refuse a run that would pay the same cycle over the same days twice.
     *
     * Scoped to the frequency on purpose: a weekly and a monthly run legitimately cover the same
     * calendar days for different people, and that is the whole point of running several cycles in
     * one company. Two *weekly* runs over the same week are not.
     *
     * Only regular runs are compared, on both sides: an off-cycle run — a bonus, a termination
     * settlement — is by definition an extra payment inside a period that has already been run.
     */
    private function assertNoOverlappingRun(
        BureauCompany $company,
        string $frequency,
        string $periodStart,
        string $periodEnd,
        ?int $ignoreRunId = null,
    ): void {
        $clash = $company->payRuns()
            ->where('frequency', $frequency)
            ->where('run_type', 'regular')
            ->when($ignoreRunId, fn ($q) => $q->where('id', '!=', $ignoreRunId))
            ->whereDate('period_start', '<=', $periodEnd)
            ->whereDate('period_end', '>=', $periodStart)
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'period_start' => sprintf(
                    'A %s run already covers those days — %s (%s to %s). Two runs on the same cycle over the same period would pay everybody twice.',
                    $frequency,
                    $clash->name,
                    $clash->period_start?->toDateString(),
                    $clash->period_end?->toDateString(),
                ),
            ]);
        }
    }

    /** Attach a once-off input (bonus/retro/leave pay-out/severance/garnishee) to a draft/review run. */
    public function addInput(BureauPayRun $run, \App\Modules\HR\Payroll\Models\Bureau\BureauEmployee $employee, array $data): \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput
    {
        // Locked from approval onwards, not only from finalising. An approved run is somebody's
        // signed-off record, and an input slipped in afterwards changes figures that carry a name.
        if (PayRunLifecycle::isLocked($run->status)) {
            throw ValidationException::withMessages([
                'run' => 'This run is ' . strtolower(PayRunLifecycle::label($run->status)) . '. Reopen it with a reason before changing it.',
            ]);
        }
        if ($employee->bureau_company_id !== $run->bureau_company_id) {
            throw ValidationException::withMessages(['employee' => 'Employee belongs to a different company.']);
        }
        $kind = $data['kind'] ?? '';
        if (! in_array($kind, \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Invalid input type.']);
        }

        // Unpaid leave: the agent enters days; deduct a pro-rata share of basic pay.
        if ($kind === 'unpaid_leave') {
            $days = (float) ($data['days'] ?? $data['amount'] ?? 0);
            if ($days <= 0) {
                throw ValidationException::withMessages(['days' => 'Enter the number of unpaid days.']);
            }
            $workingDays = $this->workingDaysPerPeriod($run->frequency);
            $amount = round(((float) $employee->basic_salary / $workingDays) * $days, 2);
            $description = $data['description'] ?? ('Unpaid leave ('.rtrim(rtrim(number_format($days, 2), '0'), '.').' day'.($days == 1 ? '' : 's').')');

            return $run->inputs()->create([
                'bureau_employee_id' => $employee->id,
                'kind'               => 'unpaid_leave',
                'description'        => $description,
                'amount'             => $amount,
                'sars_source_code'   => null,
            ]);
        }

        if (! is_numeric($data['amount'] ?? null) || (float) $data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'A positive amount is required.']);
        }

        $defaultCodes = ['bonus' => '3605', 'leave_payout' => '3605', 'retro' => '3601', 'severance' => '3901'];

        return $run->inputs()->create([
            'bureau_employee_id' => $employee->id,
            'kind'               => $kind,
            'description'        => $data['description'] ?? ucfirst(str_replace('_', ' ', $kind)),
            'amount'             => round((float) $data['amount'], 2),
            'sars_source_code'   => $data['sars_source_code'] ?? ($defaultCodes[$kind] ?? null),
        ]);
    }

    /** Standard working days in a pay period (SA convention: ~21.67/month). */
    private function workingDaysPerPeriod(string $frequency): float
    {
        return match ($frequency) {
            'weekly'      => 5.0,
            'fortnightly' => 10.0,
            default       => 21.67,
        };
    }

    /** (Re)generate and calculate payslips for every active employee. */
    public function process(BureauPayRun $run): BureauPayRun
    {
        $this->assertCanMove($run, PayRunLifecycle::REVIEW);

        return DB::transaction(function () use ($run) {
            $run->payslips()->delete(); // rebuild cleanly
            $company = $run->company;

            // Active employees, plus anyone with a once-off input on this run
            // (e.g. a terminated employee's final settlement).
            $employees = $this->employees->activeFor($company);

            // Who this run pays is the run's own roster, not "everybody active right now". A regular
            // run was seeded with everyone on its cycle when it was created; a bonus or termination
            // run holds only the people an officer put on it. Re-deriving the list here would undo
            // that and pay the whole company a bonus.
            $rosterIds = $run->rosterEmployeeIds();

            // Anybody with a once-off input on this run belongs on it whether or not somebody
            // remembered to add them — that is what attaching a bonus to a person means.
            $inputEmployeeIds = $run->inputs()->pluck('bureau_employee_id')->unique();
            $missing = $inputEmployeeIds->diff($rosterIds);

            foreach ($missing as $employeeId) {
                $this->addToRoster($run, (int) $employeeId, BureauPayRunEmployee::INPUT);
            }

            $employees = $employees
                ->whereIn('id', $rosterIds->merge($missing)->all())
                ->values();

            // Company SDL liability: annualised payroll over the threshold.
            $annualPayroll = $employees->sum(fn (BureauEmployee $e) => (float) $e->basic_salary)
                * PayrollTaxEngine::periodsFor($run->frequency);
            $ty = \App\Modules\HR\Payroll\Models\Bureau\SaTaxYear::for($run->tax_year, $company->country ?: 'ZA');
            $sdlLiable = $annualPayroll > (float) $ty->sdl_annual_threshold;

            $totals = ['gross' => 0, 'paye' => 0, 'uife' => 0, 'uifr' => 0, 'sdl' => 0, 'eti' => 0, 'ded' => 0, 'net' => 0];

            foreach ($employees as $employee) {
                $slip = $this->calculatePayslip($run, $employee, $sdlLiable);
                $totals['gross'] += $slip->gross;
                $totals['paye']  += $slip->paye;
                $totals['uife']  += $slip->uif_employee;
                $totals['uifr']  += $slip->uif_employer;
                $totals['sdl']   += $slip->sdl;
                $totals['eti']   += $slip->eti;
                $totals['ded']   += ($slip->gross - $slip->net_pay);
                $totals['net']   += $slip->net_pay;
            }

            $run->update([
                'status'             => BureauPayRun::STATUS_REVIEW,
                'employee_count'     => $employees->count(),
                'total_gross'        => round($totals['gross'], 2),
                'total_paye'         => round($totals['paye'], 2),
                'total_uif_employee' => round($totals['uife'], 2),
                'total_uif_employer' => round($totals['uifr'], 2),
                'total_sdl'          => round($totals['sdl'], 2),
                'total_eti'          => round($totals['eti'], 2),
                'total_deductions'   => round($totals['ded'], 2),
                'total_net'          => round($totals['net'], 2),
                'processed_at'       => now(),
            ]);

            $run->refresh();

            // The figures as well as the fact. A dispute about a client's month is settled by what
            // the run totalled when it was calculated, and re-reading the run today answers a
            // different question once somebody has reprocessed it.
            // Place it on the first step of the chain, so the screen can say whose signature it is
            // waiting for rather than a bare "awaiting approval".
            $this->approvals->begin($run);

            $this->audit->run($run, BureauAuditService::RUN_PROCESSED, "Pay run {$run->name} calculated.", [
                'employees'  => $employees->count(),
                'total_gross' => round($totals['gross'], 2),
                'total_paye' => round($totals['paye'], 2),
                'total_eti'  => round($totals['eti'], 2),
                'total_net'  => round($totals['net'], 2),
            ]);

            return $run;
        });
    }

    private function calculatePayslip(BureauPayRun $run, BureauEmployee $employee, bool $sdlLiable): BureauPayslip
    {
        $frequency = $run->frequency;
        $country = $run->company->country ?: 'ZA';
        $age = $employee->ageAt(Carbon::parse($run->period_end));

        // ── Build the package from basic + catalogue components ──
        $lines = [];
        $gross = 0.0;            // cash earnings (what makes up net)
        $taxableEarnings = 0.0;  // income subject to PAYE
        $uifBase = 0.0;
        $employerContributions = 0.0;
        $fringeBenefits = 0.0;
        $cashDeductions = 0.0;   // component deductions that reduce net
        $preTaxDeductions = 0.0; // deductions that also reduce taxable income

        $basic = (float) $employee->basic_salary;
        $lines[] = ['code' => 'BASIC', 'description' => 'Basic salary', 'type' => 'earning', 'amount' => $basic, 'quantity' => 1, 'taxable' => true];
        $gross += $basic; $taxableEarnings += $basic; $uifBase += $basic;

        foreach ($employee->componentAssignments()->where('active', true)->with('component')->get() as $a) {
            $c = $a->component;
            if (! $c || ! $c->active) {
                continue;
            }
            $base = $a->amount !== null ? (float) $a->amount : (float) $c->default_amount;
            $amount = round(match ($c->calc_method) {
                'percentage_of_basic' => $basic * $base / 100,
                'per_hour'            => $base * (float) $a->quantity,
                default              => $base,
            }, 2);

            $lines[] = ['code' => $c->code, 'description' => $c->name, 'type' => $c->type, 'amount' => $amount, 'quantity' => (float) $a->quantity, 'taxable' => (bool) $c->taxable];

            switch ($c->type) {
                case 'earning':
                    $gross += $amount;
                    // Only the taxable portion is subject to PAYE (e.g. a travel allowance
                    // is 80% taxable). taxable_portion defaults to 1 (fully taxable).
                    if ($c->taxable) { $taxableEarnings += round($amount * (float) ($c->taxable_portion ?? 1), 2); }
                    if ($c->affects_uif) { $uifBase += $amount; }
                    break;
                case 'deduction':
                    $cashDeductions += $amount;
                    if ($c->is_pretax) { $preTaxDeductions += $amount; }
                    break;
                case 'fringe_benefit':
                    // Non-cash taxable value: increases PAYE base, not net.
                    $fringeBenefits += $amount;
                    if ($c->taxable) { $taxableEarnings += $amount; }
                    break;
                case 'company_contribution':
                    // Employer cost; a taxable employer contribution is a fringe benefit to the employee.
                    $employerContributions += $amount;
                    if ($c->taxable) { $taxableEarnings += $amount; $fringeBenefits += $amount; }
                    break;
            }
        }

        // ── Statutory ──
        $retirement = (float) $employee->retirement_contribution; // legacy per-employee pre-tax contribution
        // s11F: retirement-fund contributions reduce taxable income only up to 27.5% /
        // the annual cap; the employee still pays the full amount, but any excess is taxed.
        $retirementContribution = $retirement + $preTaxDeductions;
        $deductibleRetirement = $this->tax->deductibleRetirement($retirementContribution, $gross, $taxableEarnings, $frequency, $run->tax_year);
        $taxableIncome = max(0.0, $taxableEarnings - $deductibleRetirement);

        if ($employee->tax_directive_percentage !== null) {
            $paye = round($gross * (float) $employee->tax_directive_percentage / 100, 2);
        } else {
            $paye = $this->tax->periodPaye($taxableIncome, $frequency, $age, $run->tax_year, $employee->medical_members, $employee->medical_dependants, $country);
        }

        $uif = $this->tax->uif($uifBase, $run->tax_year, $country, $frequency);
        $sdl = $this->tax->sdl($gross, $run->tax_year, $sdlLiable, $country);
        $medicalCredit = $this->tax->medicalCredit($employee->medical_members, $employee->medical_dependants, $run->tax_year, $country);

        // The ETI bands are defined per month, so a weekly or fortnightly wage has to be restated
        // as its monthly equivalent before it is measured against them — otherwise a weekly wage
        // of R2 000 (R8 667 a month, well over the qualifying ceiling) claims the maximum
        // incentive. The incentive itself is then scaled back to this pay period.
        $eti = 0.0;
        $monthlyRemuneration = PayrollTaxEngine::toMonthly($gross, $frequency);
        $periodKey = $this->etiClaims->periodKeyFor($run->period_end);

        // Which of the twenty-four this would be, counted from what has actually been claimed. This
        // period is excluded from the count so reprocessing a run corrects its own claim rather than
        // advancing the employee another month each time process is pressed.
        $claimMonth = $this->etiClaims->nextClaimMonth(EtiClaim::BUREAU, $employee->id, $periodKey);
        $etiReason = null;

        if (! $employee->eti_opt_in) {
            $etiReason = 'Not opted in to the incentive';
        } elseif ($claimMonth > EtiClaim::MAX_MONTHS) {
            $etiReason = 'All ' . EtiClaim::MAX_MONTHS . ' qualifying months have been claimed';
        } else {
            $monthlyEti = $this->tax->eti($monthlyRemuneration, $claimMonth, $age, $run->tax_year, $country);
            $eti = round($monthlyEti * 12 / PayrollTaxEngine::periodsFor($frequency), 2);

            if ($eti <= 0.0) {
                // Said in the employee's terms rather than "returned zero", because this is the
                // line somebody reads two years later asking why nothing was claimed in June.
                $etiReason = match (true) {
                    $age === null            => 'No date of birth, so age could not be established',
                    $age < 18 || $age > 29   => 'Age ' . $age . ' is outside the qualifying range',
                    default                  => 'Remuneration of ' . round($monthlyRemuneration, 2) . ' a month falls outside the qualifying bands',
                };
            }
        }

        // Written whether or not anything was claimed: a month that claimed nothing is a fact the
        // history has to carry, and only the eligible ones consume one of the twenty-four.
        $this->etiClaims->record(
            EtiClaim::BUREAU, $employee->id, $periodKey,
            $run->period_start, $run->period_end, (int) $run->tax_year,
            $eti, $monthlyRemuneration, $age, $claimMonth, $etiReason,
            ['bureau_company_id' => $employee->bureau_company_id, 'bureau_pay_run_id' => $run->id],
        );

        // ── Once-off inputs: bonus/13th, retro, leave pay-out, severance, garnishee ──
        $periods = PayrollTaxEngine::periodsFor($frequency);
        $onceOffTaxable = 0.0;  // bonus/retro/leave → marginal-rate (annual-diff) tax
        $severanceTotal = 0.0;  // severance → SARS lump-sum table (R550k tax-free)
        foreach ($run->inputs()->where('bureau_employee_id', $employee->id)->get() as $in) {
            $amt = (float) $in->amount;
            if ($in->isEarning()) {
                $gross += $amt;
                if ($in->kind === 'severance') {
                    $severanceTotal += $amt;
                } else {
                    $onceOffTaxable += $amt;
                }
                $lines[] = ['code' => strtoupper($in->kind), 'description' => $in->description, 'type' => 'earning', 'amount' => $amt, 'quantity' => 1, 'taxable' => true];
            } else {
                $cashDeductions += $amt;
                $lines[] = ['code' => strtoupper($in->kind), 'description' => $in->description, 'type' => 'deduction', 'amount' => $amt, 'quantity' => 1, 'taxable' => false];
            }
        }
        if ($onceOffTaxable > 0 && $employee->tax_directive_percentage === null) {
            // Tax the once-off at the marginal rate (annual-diff method), added to regular PAYE.
            $paye += $this->tax->onceOffPaye($taxableIncome * $periods, $onceOffTaxable, $run->tax_year, $country);
        } elseif ($onceOffTaxable > 0) {
            $paye += round($onceOffTaxable * (float) $employee->tax_directive_percentage / 100, 2);
        }
        if ($severanceTotal > 0) {
            // Severance is taxed on the SARS retirement/severance lump-sum table.
            $paye += $this->tax->severancePaye($severanceTotal, $run->tax_year, $country);
        }

        // ── Net: cash earnings less cash deductions (fringe/employer items don't touch net) ──
        $otherDeductions = $cashDeductions;
        $totalDeductions = $paye + $uif['employee'] + $retirement + $cashDeductions;
        $net = round($gross - $totalDeductions, 2);

        if ($retirement > 0) {
            $lines[] = ['code' => 'RA', 'description' => 'Retirement contribution', 'type' => 'deduction', 'amount' => $retirement, 'quantity' => 1, 'taxable' => false];
        }
        $lines[] = ['code' => 'PAYE', 'description' => 'PAYE', 'type' => 'deduction', 'amount' => $paye, 'quantity' => 1, 'taxable' => false];
        $lines[] = ['code' => 'UIF', 'description' => 'UIF', 'type' => 'deduction', 'amount' => $uif['employee'], 'quantity' => 1, 'taxable' => false];

        $slip = $run->payslips()->create([
            'bureau_employee_id'   => $employee->id,
            'employee_snapshot'    => [
                'name' => $employee->fullName(), 'employee_number' => $employee->employee_number,
                'id_number' => $employee->id_number, 'tax_number' => $employee->tax_number,
                'occupation' => $employee->occupation,
            ],
            'gross'                => round($gross, 2),
            'taxable_income'       => round($taxableIncome, 2),
            'paye'                 => $paye,
            'uif_employee'         => $uif['employee'],
            'uif_employer'         => $uif['employer'],
            'sdl'                  => $sdl,
            'eti'                  => $eti,
            'retirement_deduction' => $retirement,
            'medical_credit'       => $medicalCredit,
            'other_deductions'     => round($otherDeductions, 2),
            'employer_contributions' => round($employerContributions, 2),
            'fringe_benefits'      => round($fringeBenefits, 2),
            'net_pay'              => $net,
            'status'               => 'draft',
        ]);

        foreach ($lines as $line) {
            $slip->lines()->create($line);
        }

        return $slip;
    }

    /**
     * Sign the figures off.
     *
     * Its own act, and the point of the whole lifecycle. Approval used to be implicit in finalising,
     * so whoever calculated a run also approved it, locked it and released the money with no name
     * recorded against the decision — for a bureau paying other people's staff, that is the control
     * missing. The approver and the moment are kept on the run, which is what a client's auditor
     * asks for.
     *
     * This is where the blocking issues are refused, because it is the last point at which a wrong
     * payment is cheap to fix. Warnings are reported on the screen and deliberately do not refuse:
     * refusing over a missing tax number would teach people to look for the override, and then the
     * blockers stop working too.
     */
    public function approve(BureauPayRun $run, ?string $comment = null): BureauPayRun
    {
        $this->assertCanMove($run, PayRunLifecycle::APPROVED);

        $report = $this->validation->validate($run);

        if ($report['blockers'] > 0) {
            throw ValidationException::withMessages([
                'run' => 'This run cannot be approved until ' . $report['blockers'] . ' blocking issue(s) are resolved: '
                    . implode(' ', array_column(
                        array_filter($report['findings'], fn ($f) => $f['severity'] === PayrollValidationService::BLOCKER),
                        'message',
                    )),
            ]);
        }

        $this->assertSeparateApprover($run);

        // Sign the step this run is standing on. Only when the last step signs does the run itself
        // become approved — until then it stays in review, waiting on the next signature.
        if (! $this->approvals->approve($run, $comment)) {
            return $run->refresh();
        }

        $run->update([
            'status'      => PayRunLifecycle::APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->audit->run($run->refresh(), BureauAuditService::RUN_APPROVED, "Pay run {$run->name} approved.", [
            'total_net' => (float) $run->total_net,
            'warnings'  => $report['warnings'],
        ]);

        return $run;
    }

    /**
     * Whoever calculated a run should not be the one who approves it.
     *
     * Enforced only where there is somebody else who could — a one-person bureau has nobody to hand
     * it to, and refusing there would stop a real payroll for a rule that cannot be satisfied. It
     * self-adjusts: the moment a client has a second person with access, the separation applies.
     */
    private function assertSeparateApprover(BureauPayRun $run): void
    {
        $approver = auth()->id();

        if ($approver === null || $run->created_by !== $approver) {
            return;
        }

        $others = $run->company
            ? $run->company->members()->where('users.id', '!=', $approver)->count()
                + ($run->company->agent_id && $run->company->agent_id !== $approver ? 1 : 0)
            : 0;

        if ($others > 0) {
            throw ValidationException::withMessages([
                'run' => 'This run was calculated by you, so somebody else has to approve it.',
            ]);
        }
    }

    /** Lock the run: render each payslip to an encrypted PDF stored in the DB. */
    public function finalise(BureauPayRun $run): BureauPayRun
    {
        $this->assertCanMove($run, PayRunLifecycle::FINALISED);

        DB::transaction(function () use ($run) {
            foreach ($run->payslips()->with(['employee', 'lines'])->get() as $slip) {
                $employee = $slip->employee;
                $key = $employee->payslipKey() ?: $this->employees->generatePayslipKey($employee);
                $pdf = $this->secure->renderProtected($this->payslipHtml($run, $slip), $key);

                $slip->writeBytes($pdf, 'pdf');
                $slip->fill(['is_locked' => true, 'status' => 'finalised']);
                $slip->save();
            }

            $run->update(['status' => BureauPayRun::STATUS_FINALISED, 'finalised_at' => now()]);
        });

        $this->audit->run($run->refresh(), BureauAuditService::RUN_FINALISED, "Pay run {$run->name} finalised and locked.", [
            'payslips'  => $run->payslips()->count(),
            'total_net' => (float) $run->total_net,
        ]);

        // Auto-email the encrypted payslips when the company opts in (best-effort).
        if ($run->company?->auto_email_payslips) {
            $this->emailPayslips($run->refresh());
        }

        return $run->refresh();
    }

    /** Email each finalised, encrypted payslip to the employee. Returns the count sent. */
    public function emailPayslips(BureauPayRun $run): int
    {
        $sent = 0;
        foreach ($run->payslips()->with('employee')->get() as $slip) {
            $employee = $slip->employee;
            if (! $employee?->email || ! $slip->hasBytes()) {
                continue;
            }
            try {
                \Illuminate\Support\Facades\Mail::to($employee->email)->queue(new \App\Modules\HR\Payroll\Mail\BureauPayslipMail(
                    $run->company->name, $employee->fullName(), $run->name, (string) $slip->bytes(),
                ));
                $sent++;
            } catch (\Throwable) {
                // best-effort
            }
        }

        // Payslips leaving the building is an event a client asks about — "was mine sent, and when".
        $this->audit->run($run, BureauAuditService::PAYSLIPS_EMAILED, "Payslips emailed for {$run->name}.", [
            'sent' => $sent,
        ]);

        return $sent;
    }

    /** EMP201 monthly statutory summary for the run. */
    public function emp201(BureauPayRun $run): array
    {
        return [
            'paye'         => (float) $run->total_paye,
            'uif'          => round((float) $run->total_uif_employee + (float) $run->total_uif_employer, 2),
            'sdl'          => (float) $run->total_sdl,
            'eti'          => (float) $run->total_eti,
            'total_payable' => round((float) $run->total_paye + (float) $run->total_uif_employee + (float) $run->total_uif_employer + (float) $run->total_sdl - (float) $run->total_eti, 2),
        ];
    }

    private function payslipHtml(BureauPayRun $run, BureauPayslip $slip): string
    {
        $company = $run->company;
        $snap = $slip->employee_snapshot ?? [];
        $rand = fn ($n) => 'R ' . number_format((float) $n, 2);

        // Group the lines into the four sections of a full payslip.
        $section = function (string $title, $lines, bool $negative = false) use ($rand) {
            if ($lines->isEmpty()) {
                return '';
            }
            $rows = '';
            foreach ($lines as $l) {
                $rows .= "<tr><td>{$l->description}</td><td style='text-align:right'>".($negative ? '-' : '')."{$rand($l->amount)}</td></tr>";
            }

            return "<tr><td colspan='2' style='padding-top:8px;color:#555;font-weight:bold'>{$title}</td></tr>{$rows}";
        };

        $earnings = $slip->lines->where('type', 'earning');
        $deductions = $slip->lines->where('type', 'deduction');
        $fringe = $slip->lines->where('type', 'fringe_benefit');
        $employer = $slip->lines->where('type', 'company_contribution');

        $body = $section('Earnings', $earnings)
            . $section('Deductions', $deductions, true)
            . $section('Fringe benefits (taxable)', $fringe)
            . $section('Employer contributions', $employer);

        return "<html><body style='font-family:DejaVu Sans,sans-serif;font-size:12px'>"
            . "<h2 style='margin-bottom:0'>{$company->name}</h2>"
            . "<div style='color:#666'>Payslip — {$run->name} ({$run->period_start->format('d M Y')} to {$run->period_end->format('d M Y')})</div>"
            . "<hr><p><strong>".($snap['name'] ?? '')."</strong><br>Employee no: ".($snap['employee_number'] ?? '-')." · Tax no: ".($snap['tax_number'] ?? '-')."</p>"
            . "<table style='width:100%;border-collapse:collapse'>{$body}"
            . "<tr><td style='border-top:1px solid #000;padding-top:6px'><strong>Net pay</strong></td><td style='border-top:1px solid #000;text-align:right;padding-top:6px'><strong>{$rand($slip->net_pay)}</strong></td></tr>"
            . "</table>"
            . "<p style='color:#888;margin-top:18px;font-size:10px'>PAYE {$rand($slip->paye)} · UIF {$rand($slip->uif_employee)} · Medical credit {$rand($slip->medical_credit)}"
            . " · Employer contributions {$rand($slip->employer_contributions)} · Taxable fringe {$rand($slip->fringe_benefits)}</p>"
            . "</body></html>";
    }

    // ── The lifecycle ────────────────────────────────────────────────────────

    /**
     * Refuse a move the lifecycle does not allow, in the words of what is actually wrong.
     *
     * The alternative — a scatter of `if ($run->status !== 'review')` checks — is what was here
     * before, and no two of them agreed. Reading that code it was genuinely unclear whether a
     * finalised run could be reprocessed: one method said no and another never asked.
     */
    private function assertCanMove(BureauPayRun $run, string $to): void
    {
        if (PayRunLifecycle::canMove($run->status, $to)) {
            return;
        }

        $message = PayRunLifecycle::isReopenable($run->status)
            ? sprintf(
                'This run is %s. Reopen it with a reason before changing it.',
                strtolower(PayRunLifecycle::label($run->status)),
            )
            : sprintf(
                'A run that is %s cannot be moved to %s.',
                strtolower(PayRunLifecycle::label($run->status)),
                strtolower(PayRunLifecycle::label($to)),
            );

        throw ValidationException::withMessages(['run' => $message]);
    }

    /**
     * The money has gone.
     *
     * Recorded as a state rather than inferred from a disbursement row, because the money can leave
     * by a route this system never sees — a bank file downloaded and uploaded to the bank by hand,
     * which is how most South African bureaus still pay. A run stuck at "finalised" forever because
     * nobody used the integration is a run the dashboard chases every day for no reason.
     */
    public function markPaid(BureauPayRun $run, array $meta = []): BureauPayRun
    {
        $this->assertCanMove($run, PayRunLifecycle::PAID);

        $run->update(['status' => PayRunLifecycle::PAID, 'paid_at' => now()]);

        $this->audit->run($run->refresh(), BureauAuditService::RUN_PAID, "Payment released for {$run->name}.", $meta + [
            'total_net' => (float) $run->total_net,
        ]);

        return $run;
    }

    /** Paid and reported. The month is closed. */
    public function complete(BureauPayRun $run): BureauPayRun
    {
        $this->assertCanMove($run, PayRunLifecycle::COMPLETED);

        $run->update(['status' => PayRunLifecycle::COMPLETED, 'completed_at' => now()]);

        $this->audit->run($run->refresh(), BureauAuditService::RUN_COMPLETED, "Pay run {$run->name} completed.");

        return $run;
    }

    /**
     * Open a signed-off run back up, on the record.
     *
     * The requirement the specification is careful about: a completed payroll must not be editable
     * without an authorised amendment. So it is not made editable quietly — a reason is required,
     * the person is recorded, and both stay on the run afterwards rather than being cleared by the
     * next approval. A run that was reopened once is a run somebody will ask about.
     *
     * The payslips are unlocked and the approval cleared, because an approval that survives the
     * figures it approved is worse than no approval. Reopening does not unwind a payment that has
     * already gone — that is a separate correction, and pretending otherwise on the record would be
     * the more dangerous lie.
     */
    public function reopen(BureauPayRun $run, string $reason): BureauPayRun
    {
        if (! PayRunLifecycle::isReopenable($run->status)) {
            throw ValidationException::withMessages([
                'run' => 'Only an approved or later run needs reopening.',
            ]);
        }

        if (strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Say why this run is being reopened — it stays on the audit record.',
            ]);
        }

        $wasPaid = in_array($run->status, [PayRunLifecycle::PAID, PayRunLifecycle::COMPLETED], true);
        $from = $run->status;

        DB::transaction(function () use ($run, $reason) {
            $run->payslips()->update(['is_locked' => false, 'status' => 'draft']);

            $run->update([
                'status'        => PayRunLifecycle::REVIEW,
                'approved_by'   => null,
                'approved_at'   => null,
                'finalised_at'  => null,
                'paid_at'       => null,
                'completed_at'  => null,
                'reopened_at'   => now(),
                'reopened_by'   => auth()->id(),
                'reopen_reason' => trim($reason),
            ]);
        });

        $this->audit->run($run->refresh(), BureauAuditService::RUN_REOPENED, "Pay run {$run->name} reopened: " . trim($reason), [
            'from'          => $from,
            'payment_stood' => $wasPaid,
        ]);

        return $run;
    }

    // ── The roster ───────────────────────────────────────────────────────────

    /**
     * Fill a regular run with everybody on its cycle.
     *
     * An employee may be paid on a different cycle from the company default — weekly wage staff
     * alongside monthly salaried staff — so only those on this run's cycle belong on it. Both sides
     * are canonicalised: comparing raw meant a company still carrying the legacy "biweekly" never
     * matched an employee normalised to "fortnightly", and the run came out empty.
     */
    public function seedRoster(BureauPayRun $run): int
    {
        $company = $run->company;

        if (! $company) {
            return 0;
        }

        $runFrequency = PayrollTaxEngine::canonicalFrequency($run->frequency) ?? $run->frequency;
        $companyFrequency = PayrollTaxEngine::canonicalFrequency($company->pay_frequency) ?? $runFrequency;

        $added = 0;

        foreach ($this->employees->activeFor($company) as $employee) {
            $theirs = PayrollTaxEngine::canonicalFrequency($employee->pay_frequency) ?? $companyFrequency;

            if ($theirs !== $runFrequency) {
                continue;
            }

            $added += $this->addToRoster($run, (int) $employee->id, BureauPayRunEmployee::AUTO) ? 1 : 0;
        }

        return $added;
    }

    /**
     * Put somebody on a run.
     *
     * Idempotent against the unique index, so adding the same person twice cannot pay them twice —
     * which is the only failure mode here that costs money.
     */
    public function addToRoster(BureauPayRun $run, int $employeeId, string $via = BureauPayRunEmployee::MANUAL): bool
    {
        if (PayRunLifecycle::isLocked($run->status)) {
            throw ValidationException::withMessages([
                'run' => 'This run is ' . strtolower(PayRunLifecycle::label($run->status)) . '. Reopen it before changing who is on it.',
            ]);
        }

        $belongs = BureauEmployee::query()
            ->whereKey($employeeId)
            ->where('bureau_company_id', $run->bureau_company_id)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages(['employee' => 'That employee belongs to a different client.']);
        }

        $row = BureauPayRunEmployee::firstOrCreate(
            ['bureau_pay_run_id' => $run->id, 'bureau_employee_id' => $employeeId],
            ['added_via' => $via, 'added_by' => auth()->id(), 'company_id' => $run->company_id],
        );

        return $row->wasRecentlyCreated;
    }

    /** Take somebody off a run. Their payslip goes with them on the next calculation. */
    public function removeFromRoster(BureauPayRun $run, int $employeeId): void
    {
        if (PayRunLifecycle::isLocked($run->status)) {
            throw ValidationException::withMessages([
                'run' => 'This run is ' . strtolower(PayRunLifecycle::label($run->status)) . '. Reopen it before changing who is on it.',
            ]);
        }

        BureauPayRunEmployee::where('bureau_pay_run_id', $run->id)
            ->where('bureau_employee_id', $employeeId)
            ->delete();

        // The payslip is removed too, or the run keeps paying somebody who is no longer on it.
        $run->payslips()->where('bureau_employee_id', $employeeId)->delete();

        $this->audit->run($run, BureauAuditService::ROSTER_CHANGED, "An employee was removed from {$run->name}.", [
            'employee_id' => $employeeId,
        ]);
    }

    // ── Overtime, captured as hours ──────────────────────────────────────────

    /**
     * Add overtime hours for one employee, priced at the statutory multiplier.
     *
     * A bureau's clients do not clock into this system, so there is nothing to read attendance from.
     * The officer captures the hours; the engine works out what they are worth against *this*
     * employee's own rate. Asking an officer to compute the amount instead is asking them to do the
     * BCEA arithmetic per person by hand, which is exactly where the mistakes are.
     */
    public function addOvertimeHours(
        BureauPayRun $run,
        BureauEmployee $employee,
        string $kind,
        float $hours,
        ?string $description = null,
    ): \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput {
        if (! in_array($kind, \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput::HOUR_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'That is not an hours-based input.']);
        }

        if ($hours <= 0) {
            throw ValidationException::withMessages(['hours' => 'Enter the number of hours worked.']);
        }

        if (PayRunLifecycle::isLocked($run->status)) {
            throw ValidationException::withMessages([
                'run' => 'This run is ' . strtolower(PayRunLifecycle::label($run->status)) . '. Reopen it before adding overtime.',
            ]);
        }

        if ($employee->bureau_company_id !== $run->bureau_company_id) {
            throw ValidationException::withMessages(['employee' => 'That employee belongs to a different client.']);
        }

        $rates = app(\App\Modules\HR\Payroll\Support\LabourRates::class);
        $at = $run->period_end;

        $multiplier = match ($kind) {
            'sunday_time'         => $rates->sundayMultiplier($at),
            'public_holiday_time' => $rates->publicHolidayMultiplier($at),
            default               => $rates->overtimeMultiplier($at),
        };

        // The hourly rate is the package over the standard month, so somebody paid weekly is
        // restated first — otherwise a weekly wage would price an hour at four times its worth.
        $monthly = PayrollTaxEngine::toMonthly((float) $employee->basic_salary, $employee->pay_frequency ?: $run->frequency);
        $standardHours = $rates->standardMonthlyHours($at);
        $rate = $standardHours > 0 ? round($monthly / $standardHours, 2) : 0.0;

        $amount = round($rate * $multiplier * $hours, 2);

        // Replaces rather than adds, per employee per kind, so correcting a captured figure corrects
        // the pay instead of paying it twice.
        $input = $run->inputs()->updateOrCreate(
            ['bureau_employee_id' => $employee->id, 'kind' => $kind],
            [
                'description'  => $description ?: sprintf(
                    '%s — %s hrs @ %.2f× (R%s/hr)',
                    \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput::HOUR_KIND_LABELS[$kind],
                    rtrim(rtrim(number_format($hours, 2), '0'), '.'),
                    $multiplier,
                    number_format($rate, 2),
                ),
                'hours'        => $hours,
                'rate_applied' => $rate,
                'multiplier'   => $multiplier,
                'amount'       => $amount,
                'sars_source_code' => '3601',
            ],
        );

        // Attaching pay to somebody is saying they are on the run.
        $this->addToRoster($run, (int) $employee->id, BureauPayRunEmployee::INPUT);

        return $input;
    }

    /**
     * The same hours for several people at once.
     *
     * The common case on a bureau: a client sends "these six worked four hours on Saturday". Each
     * is priced against their own salary, so the same hours produce different amounts — which is
     * the whole reason to do it here rather than on a spreadsheet.
     *
     * @param  list<int>  $employeeIds
     * @return array{applied:int, total:float, skipped:list<string>}
     */
    public function addOvertimeForMany(BureauPayRun $run, array $employeeIds, string $kind, float $hours): array
    {
        $applied = 0;
        $total = 0.0;
        $skipped = [];

        foreach (BureauEmployee::whereIn('id', $employeeIds)->get() as $employee) {
            if ((float) $employee->basic_salary <= 0) {
                // No package means no rate to price the hours at, and a zero line on a payslip
                // raises a question nobody can answer later.
                $skipped[] = $employee->fullName() . ' (no basic salary on file)';
                continue;
            }

            try {
                $input = $this->addOvertimeHours($run, $employee, $kind, $hours);
                $total += (float) $input->amount;
                $applied++;
            } catch (ValidationException $e) {
                $skipped[] = $employee->fullName() . ' (' . collect($e->errors())->flatten()->first() . ')';
            }
        }

        if ($applied > 0) {
            $this->audit->run($run, BureauAuditService::ROSTER_CHANGED,
                sprintf('%s captured for %d employee(s) on %s.',
                    \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput::HOUR_KIND_LABELS[$kind],
                    $applied, $run->name),
                ['kind' => $kind, 'hours' => $hours, 'employees' => $applied, 'total' => round($total, 2)]);
        }

        return ['applied' => $applied, 'total' => round($total, 2), 'skipped' => $skipped];
    }
}
