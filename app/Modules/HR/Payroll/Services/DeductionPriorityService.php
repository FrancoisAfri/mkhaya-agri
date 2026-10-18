<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Support\Facades\DB;

/**
 * Decides what gets paid when there is not enough net pay to cover every deduction.
 *
 * Nothing decided this before. Deductions were summed and subtracted, so a month with a garnishee,
 * a maintenance order, a loan repayment and a union levy could take somebody's pay to zero or
 * below, in whatever order the rows happened to come back from the database.
 *
 * Two rules matter and neither was applied:
 *
 * 1. Order. Statutory deductions come first, then a maintenance order, then other court orders,
 *    then voluntary things like a staff loan. A maintenance order outranks a garnishee — that is
 *    why they are separate kinds rather than one "court order" — and a union levy never outranks
 *    either.
 *
 * 2. A floor. An employee has to be left with something. Where the total would breach it, the
 *    lowest-priority deductions are trimmed and the shortfall is reported so somebody can tell the
 *    creditor rather than the employee discovering it on payday.
 */
class DeductionPriorityService
{
    /**
     * Lower runs first. Statutory deductions are not negotiable, and a maintenance order is a
     * child's food before it is anybody's debt.
     */
    private const ORDER = [
        'paye'            => 10,
        'uif'             => 20,
        'maintenance'     => 30,
        'garnishee'       => 40,
        'emolument'       => 40,
        'pension'         => 50,
        'provident'       => 50,
        'medical_aid'     => 60,
        'bargaining_council' => 70,
        'union'           => 80,
        'staff_loan'      => 90,
        'other_deduction' => 95,
    ];

    private const DEFAULT_PRIORITY = 99;

    /**
     * The minimum proportion of gross an employee keeps.
     *
     * Not a statutory figure — South African law caps particular deductions rather than setting one
     * floor — but leaving somebody with nothing is never the right answer, and a configurable floor
     * turns a silent zero into a visible decision.
     */
    private function floorFraction(): float
    {
        return max(0.0, min(0.9, (float) config('payroll.minimum_take_home_fraction', 0.25)));
    }

    /**
     * @return array{
     *   applied: array<int,array{type:string,description:string,amount:float,priority:int}>,
     *   trimmed: array<int,array{type:string,description:string,requested:float,applied:float,short:float}>,
     *   total: float, shortfall: float, floor: float
     * }
     */
    public function resolve(Payslip $payslip, float $grossPay): array
    {
        $lines = $this->deductionLines($payslip);
        $floor = round($grossPay * $this->floorFraction(), 2);
        $available = max(0.0, $grossPay - $floor);

        $applied = [];
        $trimmed = [];
        $total = 0.0;

        foreach ($lines as $line) {
            $amount = (float) $line['amount'];

            // Statutory deductions are taken whatever the floor says: PAYE and UIF are owed to
            // SARS, and the floor is a protection against creditors, not against the state.
            if ($line['priority'] <= 20) {
                $applied[] = $line;
                $total += $amount;
                $available = max(0.0, $available - $amount);
                continue;
            }

            if ($amount <= $available) {
                $applied[] = $line;
                $total += $amount;
                $available -= $amount;
                continue;
            }

            $partial = round($available, 2);
            if ($partial > 0) {
                $applied[] = ['type' => $line['type'], 'description' => $line['description'],
                    'amount' => $partial, 'priority' => $line['priority']];
                $total += $partial;
            }

            $trimmed[] = [
                'type'        => $line['type'],
                'description' => $line['description'],
                'requested'   => $amount,
                'applied'     => $partial,
                'short'       => round($amount - $partial, 2),
            ];
            $available = 0.0;
        }

        return [
            'applied'   => $applied,
            'trimmed'   => $trimmed,
            'total'     => round($total, 2),
            'shortfall' => round(array_sum(array_column($trimmed, 'short')), 2),
            'floor'     => $floor,
        ];
    }

    /** Deduction lines on a payslip, ordered by what has to be paid first. */
    private function deductionLines(Payslip $payslip): array
    {
        $rows = DB::table('payroll_earnings_lines')
            ->where('payslip_id', $payslip->id)
            ->get(['line_type', 'description', 'amount']);

        $lines = [];
        foreach ($rows as $row) {
            $type = (string) $row->line_type;
            if (! $this->isDeduction($type)) {
                continue;
            }

            $lines[] = [
                'type'        => $type,
                'description' => (string) ($row->description ?: $type),
                'amount'      => (float) $row->amount,
                'priority'    => $this->priorityFor($type, (string) $row->description),
            ];
        }

        usort($lines, fn ($a, $b) => $a['priority'] <=> $b['priority'] ?: $b['amount'] <=> $a['amount']);

        return $lines;
    }

    /**
     * A line's priority.
     *
     * Court orders arrive as `other_deduction` with the nature of the order in the description,
     * because that is how a payroll officer captures them — so the description is read when the
     * line type alone does not say enough. Getting this wrong on a maintenance order is the one
     * case with a person on the other end of it.
     */
    public function priorityFor(string $lineType, string $description = ''): int
    {
        // A specific line type says everything. Only the catch-all needs its description read —
        // and it has to be read BEFORE the type is looked up, or every court order comes back as
        // a generic 95 and a maintenance order loses to a staff loan.
        if ($lineType !== 'other_deduction' && isset(self::ORDER[$lineType])) {
            return self::ORDER[$lineType];
        }

        $haystack = mb_strtolower($description);
        foreach (['maintenance', 'garnishee', 'emolument', 'union', 'staff loan', 'loan'] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return self::ORDER[str_replace(' ', '_', $keyword)] ?? self::ORDER['staff_loan'];
            }
        }

        return self::ORDER[$lineType] ?? self::DEFAULT_PRIORITY;
    }

    private function isDeduction(string $type): bool
    {
        if (str_starts_with($type, 'employer_')) {
            return false;   // a cost to the employer, never taken off the employee
        }

        return in_array($type, [
            'paye', 'uif', 'pension', 'medical_aid', 'other_deduction', 'unpaid_leave',
        ], true) || isset(self::ORDER[$type]);
    }
}
