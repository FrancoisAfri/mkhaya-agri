<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayslip;
use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use Illuminate\Support\Carbon;

/**
 * What is wrong with this run, before anybody approves it.
 *
 * Every one of these has a way of being discovered by the employee instead. A missing bank account
 * is found on payday; somebody left on the wrong pay cycle is found when they are not paid at all;
 * a duplicated account number is found by whoever received two salaries. The point of running the
 * checks beforehand is that the bureau finds them first, on a screen, while there is still time.
 *
 * Two severities, and the distinction is deliberate. A *blocker* is something that would produce a
 * demonstrably wrong payment — a negative salary, a payment file that does not add up to the run it
 * came from — and finalising past one is never the right answer. Everything else is a *warning*: a
 * missing tax number is a real problem for the IRP5 in February and no reason at all to stop paying
 * somebody in March. Refusing a run over a warning teaches people to look for the override, and then
 * the blockers stop working too.
 */
class PayrollValidationService
{
    public const BLOCKER = 'blocker';
    public const WARNING = 'warning';

    public function __construct(private readonly EtiClaimService $etiClaims)
    {
    }

    /**
     * Check a run and report everything found.
     *
     * @return array{blockers:int, warnings:int, checked:int, findings:list<array{severity:string, category:string, code:string, message:string, employee:?string, employee_number:?string}>}
     */
    public function validate(BureauPayRun $run): array
    {
        $payslips = $run->payslips()->with('employee')->get();
        $findings = array_merge(
            $this->runChecks($run, $payslips),
            $this->employeeChecks($run, $payslips),
            $this->paymentChecks($run, $payslips),
        );

        // Blockers first, then warnings, and inside each the order they were found — which groups a
        // client's problems by kind rather than scattering one employee's three issues apart.
        usort($findings, fn ($a, $b) => ($a['severity'] === self::BLOCKER ? 0 : 1) <=> ($b['severity'] === self::BLOCKER ? 0 : 1));

        return [
            'blockers' => count(array_filter($findings, fn ($f) => $f['severity'] === self::BLOCKER)),
            'warnings' => count(array_filter($findings, fn ($f) => $f['severity'] === self::WARNING)),
            'checked'  => $payslips->count(),
            'findings' => array_values($findings),
        ];
    }

    /** True when nothing found would produce a demonstrably wrong payment. */
    public function passes(BureauPayRun $run): bool
    {
        return $this->validate($run)['blockers'] === 0;
    }

    // ── The run as a whole ───────────────────────────────────────────────────

    /** @param \Illuminate\Support\Collection<int, BureauPayslip> $payslips */
    private function runChecks(BureauPayRun $run, $payslips): array
    {
        $findings = [];

        if ($payslips->isEmpty()) {
            $findings[] = $this->finding(
                self::BLOCKER, 'payroll', 'no_payslips',
                'The run has no payslips. Calculate it before approving.',
            );

            return $findings;
        }

        // Another regular run on the same cycle already covering these days. Creating one is
        // refused, but a run created before the guard existed — or one whose dates were edited
        // afterwards — is exactly the thing that pays everybody twice.
        $overlap = BureauPayRun::query()
            ->where('bureau_company_id', $run->bureau_company_id)
            ->where('id', '!=', $run->id)
            ->where('run_type', 'regular')
            ->where('frequency', $run->frequency)
            ->whereDate('period_start', '<=', $run->period_end)
            ->whereDate('period_end', '>=', $run->period_start)
            ->first();

        if ($run->run_type === 'regular' && $overlap) {
            $findings[] = $this->finding(
                self::BLOCKER, 'payroll', 'duplicate_run',
                "Another {$run->frequency} run already covers these days — {$overlap->name}. Approving both pays everybody twice.",
            );
        }

        // The totals are what the EMP201 and the payment file are built from, so they have to be
        // the sum of the payslips rather than whatever was last written to the run.
        $sums = [
            'total_gross' => round((float) $payslips->sum('gross'), 2),
            'total_paye'  => round((float) $payslips->sum('paye'), 2),
            'total_net'   => round((float) $payslips->sum('net_pay'), 2),
        ];

        foreach ($sums as $column => $expected) {
            if (abs(round((float) $run->{$column}, 2) - $expected) > 0.01) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'payroll', 'totals_mismatch',
                    sprintf(
                        'The run\'s %s (%s) does not equal the sum of its payslips (%s). Recalculate before approving.',
                        str_replace('total_', '', $column),
                        number_format((float) $run->{$column}, 2),
                        number_format($expected, 2),
                    ),
                );
            }
        }

        $taxYear = SaTaxYear::query()->where('tax_year', $run->tax_year)->first();

        if (! $taxYear) {
            $findings[] = $this->finding(
                self::BLOCKER, 'statutory', 'no_tax_year',
                "No tax tables are loaded for {$run->tax_year}, so PAYE on this run cannot be right.",
            );

            return $findings;
        }

        // A lump sum taxed against an empty table comes out at zero. That is the failure this check
        // exists for: it produced no error and no warning, so a retrenchment was simply under-taxed
        // and SARS collected the difference from the employer months later.
        $lumpSums = [
            'severance'  => ['column' => 'lump_sum_severance', 'kinds' => ['severance']],
            'withdrawal' => ['column' => 'lump_sum_withdrawal', 'kinds' => ['leave_payout']],
        ];

        foreach ($lumpSums as $label => $config) {
            $hasInput = $run->inputs()->whereIn('kind', $config['kinds'])->exists();

            if ($hasInput && blank($taxYear->{$config['column']})) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'statutory', 'no_lump_sum_table',
                    "This run has a {$label} payment but no {$label} tax table is loaded for {$run->tax_year}, "
                        . 'so it would be taxed at nothing at all.',
                );
            }
        }

        return $findings;
    }

    // ── One employee at a time ───────────────────────────────────────────────

    /** @param \Illuminate\Support\Collection<int, BureauPayslip> $payslips */
    private function employeeChecks(BureauPayRun $run, $payslips): array
    {
        $findings = [];
        $seenIdentity = [];
        $seenAccount = [];
        $periodEnd = Carbon::parse($run->period_end);
        $companyFrequency = PayrollTaxEngine::canonicalFrequency($run->company?->pay_frequency) ?? $run->frequency;

        foreach ($payslips as $slip) {
            $employee = $slip->employee;

            if (! $employee) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'employee', 'orphan_payslip',
                    'A payslip on this run has no employee record behind it.',
                );
                continue;
            }

            $who = $employee->fullName();
            $number = $employee->employee_number;

            // Payment
            if (blank($employee->account_number) || blank($employee->branch_code)) {
                $findings[] = $this->finding(self::WARNING, 'payment', 'no_bank_account',
                    "{$who} has no bank account, so they will be left out of the payment file.", $who, $number);
            } elseif (! $this->plausibleAccount($employee)) {
                $findings[] = $this->finding(self::WARNING, 'payment', 'invalid_bank_account',
                    "{$who}'s bank details do not look valid — a branch code is six digits and an account number at least six.", $who, $number);
            }

            // Statutory identity. Not a blocker for a March payment; a real problem for February's
            // IRP5, which is why it is raised now rather than discovered at year end.
            if (blank($employee->id_number) && blank($employee->passport_number)) {
                $findings[] = $this->finding(self::WARNING, 'statutory', 'no_identity',
                    "{$who} has neither an ID nor a passport number. SARS will reject their tax certificate.", $who, $number);
            }

            if (blank($employee->tax_number)) {
                $findings[] = $this->finding(self::WARNING, 'statutory', 'no_tax_number',
                    "{$who} has no tax reference number.", $who, $number);
            }

            // Employment dates
            if (blank($employee->start_date)) {
                $findings[] = $this->finding(self::WARNING, 'employee', 'no_start_date',
                    "{$who} has no start date, so leave and the incentive cannot be worked out for them.", $who, $number);
            } elseif (Carbon::parse($employee->start_date)->gt($periodEnd)) {
                $findings[] = $this->finding(self::BLOCKER, 'employee', 'starts_after_period',
                    "{$who} starts on " . Carbon::parse($employee->start_date)->toDateString() . ", after this period ends. They are being paid before they have worked.", $who, $number);
            }

            if ($employee->end_date && Carbon::parse($employee->end_date)->lt($run->period_start)) {
                $findings[] = $this->finding(self::BLOCKER, 'employee', 'left_before_period',
                    "{$who} left on " . Carbon::parse($employee->end_date)->toDateString() . ", before this period began.", $who, $number);
            }

            // On the right cycle. Somebody quietly moved between frequencies is either paid twice or
            // not at all, and neither is visible on the run itself.
            $theirs = PayrollTaxEngine::canonicalFrequency($employee->pay_frequency) ?? $companyFrequency;
            if ($theirs !== (PayrollTaxEngine::canonicalFrequency($run->frequency) ?? $run->frequency)) {
                $findings[] = $this->finding(self::BLOCKER, 'employee', 'wrong_frequency',
                    "{$who} is paid {$theirs} but is on a {$run->frequency} run.", $who, $number);
            }

            // The same person twice, under two records
            $identity = $employee->id_number ?: $employee->passport_number;
            if ($identity && isset($seenIdentity[$identity])) {
                $findings[] = $this->finding(self::BLOCKER, 'employee', 'duplicate_employee',
                    "{$who} and {$seenIdentity[$identity]} share an ID number, so somebody is on this run twice.", $who, $number);
            } elseif ($identity) {
                $seenIdentity[$identity] = $who;
            }

            $account = preg_replace('/\D/', '', (string) $employee->account_number);
            if ($account && isset($seenAccount[$account])) {
                // Legitimate for a married couple; usually a copied row. Warned rather than blocked
                // because refusing it would stop a real payroll for a real reason nobody can fix.
                $findings[] = $this->finding(self::WARNING, 'payment', 'shared_account',
                    "{$who} and {$seenAccount[$account]} are paid into the same bank account.", $who, $number);
            } elseif ($account) {
                $seenAccount[$account] = $who;
            }

            // Money
            if ((float) $slip->net_pay < 0) {
                $findings[] = $this->finding(self::BLOCKER, 'payroll', 'negative_net',
                    "{$who}'s net pay is negative (" . number_format((float) $slip->net_pay, 2) . '). Their deductions exceed their earnings.', $who, $number);
            }

            if ((float) $slip->gross > 0 && (float) $slip->paye < 0) {
                $findings[] = $this->finding(self::BLOCKER, 'statutory', 'negative_paye',
                    "{$who}'s PAYE calculated as a negative amount.", $who, $number);
            }

            $findings = array_merge($findings, $this->varianceCheck($run, $slip, $employee));
            $findings = array_merge($findings, $this->etiChecks($slip, $employee, $periodEnd));
        }

        return $findings;
    }

    /**
     * A salary that moved a long way since the last run.
     *
     * Not wrong by itself — a bonus, a promotion and a first part-month all look like this — but it
     * is the shape a fat-fingered extra zero takes, and that is only ever caught before payment.
     */
    private function varianceCheck(BureauPayRun $run, BureauPayslip $slip, BureauEmployee $employee): array
    {
        $previous = BureauPayslip::query()
            ->where('bureau_employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->where('id', '!=', $run->id)
                ->where('frequency', $run->frequency)
                ->whereDate('period_end', '<', $run->period_start))
            ->latest('id')
            ->first();

        if (! $previous || (float) $previous->gross <= 0) {
            return [];
        }

        $change = ((float) $slip->gross - (float) $previous->gross) / (float) $previous->gross;

        if (abs($change) < 0.4) {
            return [];
        }

        return [$this->finding(
            self::WARNING, 'payroll', 'salary_variance',
            sprintf(
                "%s's gross moved %s%% from the previous run (%s to %s).",
                $employee->fullName(),
                number_format($change * 100, 0),
                number_format((float) $previous->gross, 2),
                number_format((float) $slip->gross, 2),
            ),
            $employee->fullName(),
            $employee->employee_number,
        )];
    }

    /**
     * The incentive, checked against the ledger rather than against tenure.
     *
     * Claiming past twenty-four months is money SARS takes back with interest, so it is worth
     * saying out loud on the run that would have done it.
     */
    private function etiChecks(BureauPayslip $slip, BureauEmployee $employee, Carbon $periodEnd): array
    {
        $findings = [];
        $claimed = (float) $slip->eti;
        $who = $employee->fullName();
        $number = $employee->employee_number;

        if ($claimed <= 0) {
            // Somebody in an incentive category earning nothing from it is worth a look — usually
            // their age or their remuneration puts them outside it, which is fine, but a client who
            // thinks they are claiming and is not should be told.
            if (EmploymentCategory::tracksEti($employee->employment_category) && ! $employee->eti_opt_in) {
                $findings[] = $this->finding(self::WARNING, 'eti', 'category_not_opted_in',
                    "{$who} is recorded as {$employee->employment_category} but is not opted in to the incentive.", $who, $number);
            }

            return $findings;
        }

        $used = $this->etiClaims->claimedMonths(EtiClaim::BUREAU, (int) $employee->id, $this->etiClaims->periodKeyFor($periodEnd));

        if ($used >= EtiClaim::MAX_MONTHS) {
            $findings[] = $this->finding(self::BLOCKER, 'eti', 'eti_exhausted',
                "{$who} has already claimed all " . EtiClaim::MAX_MONTHS . ' qualifying months, but this run claims more. SARS recovers this with interest.', $who, $number);
        } elseif ($used >= EtiClaim::MAX_MONTHS - 3) {
            $findings[] = $this->finding(self::WARNING, 'eti', 'eti_ending',
                "{$who} has " . (EtiClaim::MAX_MONTHS - $used) . ' incentive month(s) left.', $who, $number);
        }

        $age = $employee->ageAt($periodEnd);
        if ($age !== null && ($age < 18 || $age > 29)) {
            $findings[] = $this->finding(self::BLOCKER, 'eti', 'eti_age',
                "{$who} is {$age} and outside the incentive's age range, but this run claims for them.", $who, $number);
        }

        return $findings;
    }

    // ── What the payment file will contain ───────────────────────────────────

    /** @param \Illuminate\Support\Collection<int, BureauPayslip> $payslips */
    private function paymentChecks(BureauPayRun $run, $payslips): array
    {
        $findings = [];
        $company = $run->company;

        if (blank($company?->bank_account_number) || blank($company?->bank)) {
            $findings[] = $this->finding(self::WARNING, 'payment', 'no_employer_account',
                "No bank account is set for {$company?->name}, so no payment file can be generated.");
        }

        // What would actually be paid, against what the run says it owes. The gap is always the
        // people with no bank account — stated as a figure, because "twelve employees are missing"
        // and "R184 000 is missing" are read very differently.
        $payable = 0.0;
        $unpaid = 0.0;

        foreach ($payslips as $slip) {
            $net = (float) $slip->net_pay;

            if ($net <= 0) {
                continue;
            }

            $employee = $slip->employee;

            if (! $employee || blank($employee->account_number) || blank($employee->branch_code)) {
                $unpaid += $net;
                continue;
            }

            $payable += $net;
        }

        if ($unpaid > 0.01) {
            $findings[] = $this->finding(self::WARNING, 'payment', 'payment_total_gap',
                sprintf(
                    'The payment file will total %s, which is %s short of the run\'s net pay — that difference is people with no bank account.',
                    number_format($payable, 2),
                    number_format($unpaid, 2),
                ));
        }

        return $findings;
    }

    private function plausibleAccount(BureauEmployee $employee): bool
    {
        $branch = preg_replace('/\D/', '', (string) $employee->branch_code);
        $account = preg_replace('/\D/', '', (string) $employee->account_number);

        return strlen($branch) === 6 && strlen($account) >= 6 && strlen($account) <= 16;
    }

    private function finding(
        string $severity,
        string $category,
        string $code,
        string $message,
        ?string $employee = null,
        ?string $number = null,
    ): array {
        return [
            'severity'        => $severity,
            'category'        => $category,
            'code'            => $code,
            'message'         => $message,
            'employee'        => $employee,
            'employee_number' => $number,
        ];
    }
}
