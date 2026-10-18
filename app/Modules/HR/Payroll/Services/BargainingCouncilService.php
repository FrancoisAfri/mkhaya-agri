<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Enums\EarningsLineType;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calculates the bargaining council levy for everyone a council covers.
 *
 * An employer party to a council deducts a levy from each covered employee and pays it over with
 * its own contribution. None of this was calculated — the only thing named after bargaining
 * councils in this codebase was a BCEA overtime check — so an employer in a council sector added a
 * manual deduction line every month and reconciled it by hand.
 *
 * Covered is per person, not per company: one employer routinely has covered and uncovered staff
 * in the same run, and levying everybody would be as wrong as levying nobody.
 */
class BargainingCouncilService
{
    /**
     * Applies the levy to every payslip in a run.
     *
     * Replaces its own lines rather than adding to them, so re-processing a run does not levy
     * somebody twice — the same rule the overtime calculator follows.
     *
     * @return array{employees:int, employee_total:float, employer_total:float}
     */
    public function applyToRun(PayrollRun $run): array
    {
        $memberships = $this->membershipsFor($run);
        if ($memberships->isEmpty()) {
            return ['employees' => 0, 'employee_total' => 0.0, 'employer_total' => 0.0];
        }

        $employeeTotal = 0.0;
        $employerTotal = 0.0;
        $count = 0;

        foreach ($run->payslips as $payslip) {
            $membership = $memberships->get($payslip->employee_id);
            if (! $membership) {
                continue;
            }

            $rate = $this->rateOn($membership->council_id, $run->period_end);
            if (! $rate) {
                continue;
            }

            $levy = $this->levyFor($rate, (float) $payslip->basic_salary);
            if ($levy['employee'] <= 0 && $levy['employer'] <= 0) {
                continue;
            }

            // Ours to replace: a re-run must not stack a second levy on the same payslip.
            DB::table('payroll_earnings_lines')
                ->where('payslip_id', $payslip->id)
                ->where('source', 'bargaining_council')
                ->delete();

            if ($levy['employee'] > 0) {
                $this->line($payslip->id, EarningsLineType::OtherDeduction->value,
                    $membership->council_name . ' levy', $levy['employee'], $run);
                $employeeTotal += $levy['employee'];
            }

            if ($levy['employer'] > 0) {
                $this->line($payslip->id, 'employer_council',
                    $membership->council_name . ' employer levy', $levy['employer'], $run);
                $employerTotal += $levy['employer'];
            }

            $count++;
        }

        return [
            'employees'      => $count,
            'employee_total' => round($employeeTotal, 2),
            'employer_total' => round($employerTotal, 2),
        ];
    }

    /**
     * @return array{employee: float, employer: float}
     */
    public function levyFor(object $rate, float $basicPay): array
    {
        if ($rate->basis === 'percentage') {
            $employee = $basicPay * ((float) $rate->employee_percent / 100);
            $employer = $basicPay * ((float) $rate->employer_percent / 100);

            // The council's ceiling. Without it a high earner is over-deducted every month and the
            // council refunds it a year later, if anybody notices.
            if ($rate->employee_ceiling !== null) {
                $employee = min($employee, (float) $rate->employee_ceiling);
            }
            if ($rate->employer_ceiling !== null) {
                $employer = min($employer, (float) $rate->employer_ceiling);
            }

            return ['employee' => round($employee, 2), 'employer' => round($employer, 2)];
        }

        return [
            'employee' => round((float) $rate->employee_amount, 2),
            'employer' => round((float) $rate->employer_amount, 2),
        ];
    }

    /** The rate in force on a date. A council gazettes new ones and old payslips keep the old. */
    public function rateOn(int $councilId, $date): ?object
    {
        $day = Carbon::parse($date)->toDateString();

        return DB::table('bargaining_council_rates')
            ->where('council_id', $councilId)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();
    }

    /** Employees covered during this run's period, keyed by employee id. */
    private function membershipsFor(PayrollRun $run)
    {
        $start = Carbon::parse($run->period_start)->toDateString();
        $end = Carbon::parse($run->period_end)->toDateString();

        return DB::table('bargaining_council_members as m')
            ->join('bargaining_councils as c', 'c.id', '=', 'm.council_id')
            ->where('c.active', true)
            // Somebody who left the council mid-year is not levied for the months after it.
            ->where(fn ($q) => $q->whereNull('m.joined_on')->orWhereDate('m.joined_on', '<=', $end))
            ->where(fn ($q) => $q->whereNull('m.left_on')->orWhereDate('m.left_on', '>=', $start))
            ->select('m.employee_id', 'm.council_id', 'c.name as council_name')
            ->get()
            ->keyBy('employee_id');
    }

    private function line(int $payslipId, string $type, string $description, float $amount, PayrollRun $run): void
    {
        DB::table('payroll_earnings_lines')->insert([
            'payslip_id'  => $payslipId,
            'line_type'   => $type,
            'description' => $description,
            'amount'      => $amount,
            'quantity'    => 1,
            'source'      => 'bargaining_council',
            'company_id'  => $run->company_id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
