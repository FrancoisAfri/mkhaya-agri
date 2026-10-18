<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Enums\EarningsLineType;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Support\LabourRates;
use Illuminate\Support\Facades\DB;

/**
 * What the BCEA says overtime is worth, computed from the hours actually worked.
 *
 * The module could already *detect* an underpayment — more than ten hours of overtime in a week, a
 * multiplier under 1.5 — but it could not *pay* one correctly: overtime arrived as an amount somebody
 * typed in, and the only rate anywhere was a single `overtime_rate` setting. There was no structure
 * of 1.5× ordinary overtime, 2× Sunday and 2× public holiday, which is what §10, §16 and §18
 * actually require.
 *
 * Deliberately not automatic. This produces the lines and hands them back; applying them changes
 * what people are paid, and that is a decision somebody makes on a screen with the figures in front
 * of them — not a side effect of pressing calculate.
 */
class OvertimeCalculator
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly LabourRates $rates,
    ) {
    }

    /**
     * What this employee is owed for the period, by category.
     *
     * @return array{
     *   rate: float, ordinary_hours: float,
     *   lines: list<array{type:string, description:string, hours:float, rate:float, amount:float}>,
     *   total: float
     * }
     */
    public function forPayslip(Payslip $payslip): array
    {
        $hours = $this->payroll->hoursFor($payslip);

        if ($hours === []) {
            return ['rate' => 0.0, 'ordinary_hours' => 0.0, 'lines' => [], 'total' => 0.0];
        }

        $at = $payslip->period_end;
        $rate = (float) $hours['rate'];

        $categories = [
            // Overtime is paid at the multiplier on top of nothing — the hours are extra, so the
            // whole amount is new money.
            ['key' => 'overtime', 'hours' => (float) $hours['overtime'],
             'multiplier' => $this->rates->overtimeMultiplier($at), 'label' => 'Overtime'],

            ['key' => 'sunday', 'hours' => (float) $hours['sunday'],
             'multiplier' => $this->rates->sundayMultiplier($at), 'label' => 'Sunday work'],

            ['key' => 'public_holiday', 'hours' => (float) $hours['public_holiday'],
             'multiplier' => $this->rates->publicHolidayMultiplier($at), 'label' => 'Public holiday work'],
        ];

        $lines = [];
        $total = 0.0;

        foreach ($categories as $c) {
            if ($c['hours'] <= 0 || $rate <= 0) {
                continue;
            }

            $lineRate = round($rate * $c['multiplier'], 2);
            $amount = round($lineRate * $c['hours'], 2);
            $total += $amount;

            $lines[] = [
                'type'        => $c['key'],
                'description' => sprintf('%s — %s hrs @ %.2f× (R%s/hr)',
                    $c['label'], rtrim(rtrim(number_format($c['hours'], 2), '0'), '.'),
                    $c['multiplier'], number_format($lineRate, 2)),
                'hours'       => $c['hours'],
                'rate'        => $lineRate,
                'amount'      => $amount,
            ];
        }

        return [
            'rate'           => $rate,
            'ordinary_hours' => (float) $hours['ordinary'],
            'lines'          => $lines,
            'total'          => round($total, 2),
        ];
    }

    /**
     * Write the computed lines onto the payslip, replacing any this calculator wrote before.
     *
     * Replacing rather than adding is what makes it safe to run twice: pressing the button again
     * after correcting a timesheet corrects the pay instead of doubling it. Lines somebody entered
     * by hand are left alone — they are not ours to remove.
     *
     * @return int how many lines were written
     */
    public function applyToPayslip(Payslip $payslip): int
    {
        $computed = $this->forPayslip($payslip);

        DB::transaction(function () use ($payslip, $computed) {
            $payslip->earningsLines()
                ->where('line_type', EarningsLineType::Overtime->value)
                ->where('source', 'attendance')
                ->delete();

            foreach ($computed['lines'] as $line) {
                $payslip->earningsLines()->create([
                    'line_type'   => EarningsLineType::Overtime->value,
                    'description' => $line['description'],
                    'quantity'    => $line['hours'],
                    'amount'      => $line['amount'],
                    'source'      => 'attendance',
                ]);
            }
        });

        return count($computed['lines']);
    }

    /**
     * Apply to every payslip on a run.
     *
     * @return array{payslips:int, lines:int, total:float}
     */
    public function applyToRun(PayrollRun $run): array
    {
        $payslips = 0;
        $lines = 0;
        $total = 0.0;

        foreach ($run->payslips()->get() as $payslip) {
            $computed = $this->forPayslip($payslip);

            if ($computed['lines'] === []) {
                continue;
            }

            $lines += $this->applyToPayslip($payslip);
            $total += $computed['total'];
            $payslips++;
        }

        return ['payslips' => $payslips, 'lines' => $lines, 'total' => round($total, 2)];
    }
}
