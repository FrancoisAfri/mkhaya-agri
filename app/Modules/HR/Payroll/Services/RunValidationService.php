<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine;
use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is wrong with this run, before anybody releases it.
 *
 * The bureau has had this since PayrollValidationService was written; the in-house payroll never
 * did. An employer running its own payroll could lock, submit and release a run with no check at
 * all beyond the anomaly scan, which looks for outliers rather than for errors.
 *
 * Every finding below has a way of being discovered by the employee instead. A missing bank account
 * is found on payday. Somebody on the wrong pay cycle is found when they are not paid at all. Two
 * runs covering the same days are found by whoever received two salaries. The point of running the
 * checks beforehand is that payroll finds them first, on a screen, while there is still time.
 *
 * ── Two severities, and the distinction matters ─────────────────────────────
 *
 * A *blocker* is something that would produce a demonstrably wrong payment: a negative net, totals
 * that do not equal the payslips they are built from, an employee on the run twice. Releasing past
 * one is never right.
 *
 * Everything else is a *warning*. A missing tax number is a real problem for the IRP5 in February
 * and no reason at all to stop paying somebody in March. Refusing a run over a warning teaches
 * people to look for the override, and then the blockers stop working too.
 */
class RunValidationService
{
    public const BLOCKER = 'blocker';
    public const WARNING = 'warning';

    public function __construct(private readonly EtiClaimService $etiClaims)
    {
    }

    /**
     * @return array{blockers:int, warnings:int, checked:int, findings:list<array<string,mixed>>}
     */
    public function validate(PayrollRun $run): array
    {
        $payslips = DB::table('payslips')
            ->where('payroll_run_id', $run->id)
            ->whereNull('deleted_at')
            ->get();

        $findings = array_merge(
            $this->runChecks($run, $payslips),
            $this->employeeChecks($run, $payslips),
        );

        // Blockers first, then warnings, and inside each the order they were found — which groups
        // problems by kind rather than scattering one employee's three issues apart.
        usort($findings, fn ($a, $b) => ($a['severity'] === self::BLOCKER ? 0 : 1) <=> ($b['severity'] === self::BLOCKER ? 0 : 1));

        return [
            'blockers' => count(array_filter($findings, fn ($f) => $f['severity'] === self::BLOCKER)),
            'warnings' => count(array_filter($findings, fn ($f) => $f['severity'] === self::WARNING)),
            'checked'  => $payslips->count(),
            'findings' => array_values($findings),
        ];
    }

    /** Whether anything on this run would produce a wrong payment. */
    public function hasBlockers(PayrollRun $run): bool
    {
        return $this->validate($run)['blockers'] > 0;
    }

    /** @param  \Illuminate\Support\Collection<int, object>  $payslips */
    private function runChecks(PayrollRun $run, $payslips): array
    {
        $findings = [];

        if ($payslips->isEmpty()) {
            return [$this->finding(
                self::BLOCKER, 'payroll', 'no_payslips',
                'The run has no payslips. Add employees and calculate before releasing.',
            )];
        }

        /*
         * Another run on the same cycle already covering these days.
         *
         * The payslip overlap constraint refuses the second payment at the row level, but it fires
         * during a release that is already under way — this says so on a screen beforehand, which
         * is where somebody can still do something about it.
         */
        $overlap = PayrollRun::query()
            ->where('id', '!=', $run->id)
            ->where('company_id', $run->company_id)
            ->where('frequency', $run->frequency)
            ->whereNotIn('status', [PayrollRunStatus::Cancelled->value])
            ->whereDate('period_start', '<=', $run->period_end)
            ->whereDate('period_end', '>=', $run->period_start)
            ->first();

        if ($overlap) {
            $findings[] = $this->finding(
                self::BLOCKER, 'payroll', 'duplicate_run',
                sprintf(
                    'Another %s run already covers these days — %s. Releasing both pays everybody twice.',
                    $run->frequency ?: 'monthly',
                    $overlap->name,
                ),
            );
        }

        // The totals are what the EMP201, the bank file and the GL posting are built from, so they
        // have to be the sum of the payslips rather than whatever was last written to the run.
        $sums = [
            'total_gross'      => round((float) $payslips->sum('gross_pay'), 2),
            'total_deductions' => round((float) $payslips->sum('total_deductions'), 2),
            'total_net'        => round((float) $payslips->sum('net_pay'), 2),
        ];

        foreach ($sums as $column => $expected) {
            if (abs(round((float) $run->{$column}, 2) - $expected) > 0.01) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'payroll', 'totals_mismatch',
                    sprintf(
                        "The run's %s (%s) does not equal the sum of its payslips (%s). Recalculate before releasing.",
                        str_replace('total_', '', $column),
                        number_format((float) $run->{$column}, 2),
                        number_format($expected, 2),
                    ),
                );
            }
        }

        if ((int) $run->employee_count !== $payslips->count()) {
            $findings[] = $this->finding(
                self::WARNING, 'payroll', 'count_mismatch',
                sprintf(
                    'The run says %d employee(s) but carries %d payslip(s). Recalculate to bring them back in step.',
                    (int) $run->employee_count,
                    $payslips->count(),
                ),
            );
        }

        $taxYear = PayrollTaxEngine::taxYearFor(Carbon::parse($run->period_end));

        if (! SaTaxYear::query()->where('tax_year', $taxYear)->exists()) {
            $findings[] = $this->finding(
                self::BLOCKER, 'statutory', 'no_tax_year',
                "No tax tables are loaded for {$taxYear}, so the PAYE on this run cannot be right.",
            );
        }

        return $findings;
    }

    /** @param  \Illuminate\Support\Collection<int, object>  $payslips */
    private function employeeChecks(PayrollRun $run, $payslips): array
    {
        $findings = [];
        $employeeIds = $payslips->pluck('employee_id')->all();

        $people = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->get()
            ->keyBy('user_id');

        $runFrequency = PayrollTaxEngine::canonicalFrequency($run->frequency) ?? 'monthly';
        $accounts = [];
        $seen = [];

        foreach ($payslips as $slip) {
            $person = $people[$slip->employee_id] ?? null;
            $name = $person
                ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
                : "Employee #{$slip->employee_id}";
            $number = $person->employee_number ?? null;

            // The same person twice on one run. The unique index refuses it, but a run built before
            // that index existed still carries it, and it pays somebody twice.
            if (isset($seen[$slip->employee_id])) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'employee', 'duplicate_employee',
                    "{$name} appears on this run more than once, so they would be paid twice.",
                    $name, $number,
                );
            }
            $seen[$slip->employee_id] = true;

            if (! $person) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'employee', 'no_hr_record',
                    "Employee #{$slip->employee_id} has no HR record, so there is nothing to certify them against and no bank details to pay.",
                );

                continue;
            }

            if (round((float) $slip->net_pay, 2) < 0) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'payroll', 'negative_net',
                    sprintf('%s has a negative net pay (%s). Deductions exceed earnings.', $name, number_format((float) $slip->net_pay, 2)),
                    $name, $number,
                );
            }

            if (round((float) $slip->paye, 2) < 0) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'statutory', 'negative_paye',
                    "{$name} has negative PAYE. SARS does not accept a negative declaration.",
                    $name, $number,
                );
            }

            // Somebody on another cycle is on the wrong run. They would be paid for a period they
            // are not on, and paid again by their own run for the same days.
            $theirs = PayrollTaxEngine::canonicalFrequency($person->pay_frequency ?? null) ?? $runFrequency;

            if ($theirs !== $runFrequency) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'employee', 'wrong_frequency',
                    "{$name} is paid {$theirs}, not {$runFrequency}. They belong on their own run.",
                    $name, $number,
                );
            }

            $left = $this->fromStoredDate($person->date_left ?? null);

            if ($left && $left->lt(Carbon::parse($run->period_start))) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'employee', 'left_before_period',
                    sprintf('%s left on %s, before this period started.', $name, $left->toDateString()),
                    $name, $number,
                );
            }

            // Warnings from here down: real problems, none of them a reason to stop paying somebody.
            if (blank($person->account_number ?? null)) {
                $findings[] = $this->finding(
                    self::WARNING, 'payment', 'no_bank_account',
                    "{$name} has no bank account, so they cannot be paid electronically.",
                    $name, $number,
                );
            } else {
                $account = preg_replace('/\D/', '', (string) $person->account_number);

                // Two people paid into one account is sometimes a couple and sometimes a fraud, and
                // the only way to tell is for somebody to look.
                if (isset($accounts[$account])) {
                    $findings[] = $this->finding(
                        self::WARNING, 'payment', 'shared_account',
                        "{$name} shares a bank account with {$accounts[$account]}.",
                        $name, $number,
                    );
                }
                $accounts[$account] = $name;
            }

            if (blank($person->id_number ?? null) && blank($person->passport_number ?? null)) {
                $findings[] = $this->finding(
                    self::WARNING, 'statutory', 'no_identity',
                    "{$name} has no ID or passport number, which SARS requires on their certificate.",
                    $name, $number,
                );
            }

            if (blank($person->income_tax_number ?? null)) {
                $findings[] = $this->finding(
                    self::WARNING, 'statutory', 'no_tax_number',
                    "{$name} has no income tax number. The IRP5 can be issued without one, but SARS will query it.",
                    $name, $number,
                );
            }

            // The incentive is claimed on this run, so an employee who has run out of months is
            // about to claim a twenty-fifth — which SARS reverses, with interest.
            if (round((float) ($slip->eti ?? 0), 2) > 0
                && $this->etiClaims->isExhausted(EtiClaim::HR, (int) $slip->employee_id)) {
                $findings[] = $this->finding(
                    self::BLOCKER, 'eti', 'eti_exhausted',
                    "{$name} has used all 24 ETI months but this run still claims the incentive for them.",
                    $name, $number,
                );
            }
        }

        return $findings;
    }

    /**
     * hr_people stores some dates as Unix timestamps in a bigint column and some as strings.
     *
     * Carbon::parse() on the bigint throws, which ProRataCalculator already documents as having
     * caught this module out before — and caught this service out too, until a test hit it.
     */
    private function fromStoredDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function finding(
        string $severity,
        string $category,
        string $code,
        string $message,
        ?string $employee = null,
        ?string $employeeNumber = null,
    ): array {
        return [
            'severity'        => $severity,
            'category'        => $category,
            'code'            => $code,
            'message'         => $message,
            'employee'        => $employee,
            'employee_number' => $employeeNumber,
        ];
    }
}
