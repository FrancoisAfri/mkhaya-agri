<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Remittance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Works out what each fund is owed for a run, and tracks whether it was paid.
 *
 * Deductions came off the payslip and stopped there. The employee's net pay was right, the medical
 * aid never heard from us, and there was no way to answer "did September's provident payment go?"
 * without ringing the bank. Money held on somebody else's behalf with no record of paying it
 * across is how an employer ends up in front of a determination.
 *
 * The schedule matters as much as the total. A fund querying a payment asks who it was for, and
 * reconstructing that months later from payslips is a bad afternoon — so the per-employee
 * breakdown is stored with the amount.
 */
class RemittanceService
{
    /**
     * Builds or refreshes every remittance for a run.
     *
     * Safe to run repeatedly: a recalculation updates the existing row rather than creating a
     * second one, and a remittance already marked paid is left alone because the money has gone
     * and changing the record afterwards would hide a discrepancy rather than show it.
     *
     * @return array{created:int, updated:int, skipped:int, total:float}
     */
    public function buildForRun(PayrollRun $run): array
    {
        $beneficiaries = Beneficiary::query()->where('active', true)->get();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0.0;

        foreach ($beneficiaries as $beneficiary) {
            $lineTypes = array_values(array_filter((array) $beneficiary->line_types));
            if ($lineTypes === []) {
                continue;
            }

            $rows = $this->linesFor($run, $lineTypes);
            if ($rows->isEmpty()) {
                continue;
            }

            $existing = Remittance::where('beneficiary_id', $beneficiary->id)
                ->where('payroll_run_id', $run->id)
                ->first();

            if ($existing && $existing->status === 'paid') {
                // Already paid. A later recalculation that disagrees is a discrepancy to surface,
                // not a number to quietly overwrite.
                $skipped++;
                continue;
            }

            $employee = (float) $rows->sum('employee_amount');
            $employer = (float) $rows->sum('employer_amount');

            $attributes = [
                'period_start'     => $run->period_start,
                'period_end'       => $run->period_end,
                'employee_portion' => $employee,
                'employer_portion' => $employer,
                'total'            => $employee + $employer,
                'employee_count'   => $rows->count(),
                'status'           => 'due',
                'due_on'           => $this->dueDate($beneficiary, $run->period_end),
                'schedule'         => $rows->map(fn ($r) => [
                    'employee_id' => $r->employee_id,
                    'name'        => trim(($r->first_name ?? '') . ' ' . ($r->surname ?? '')),
                    'employee_number' => $r->employee_number,
                    'id_number'   => $r->id_number,
                    'employee'    => round((float) $r->employee_amount, 2),
                    'employer'    => round((float) $r->employer_amount, 2),
                ])->values()->all(),
            ];

            if ($existing) {
                $existing->update($attributes);
                $updated++;
            } else {
                Remittance::create($attributes + [
                    'beneficiary_id' => $beneficiary->id,
                    'payroll_run_id' => $run->id,
                ]);
                $created++;
            }

            $total += $employee + $employer;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'total' => round($total, 2)];
    }

    /**
     * Per employee, for the deduction types this beneficiary receives.
     *
     * Employer contributions are summed separately from the employee's own, because a fund's
     * schedule lists both and the two are not interchangeable when a payment is queried.
     */
    private function linesFor(PayrollRun $run, array $lineTypes)
    {
        $employerTypes = array_values(array_filter($lineTypes, fn ($t) => str_starts_with($t, 'employer_')));
        $employeeTypes = array_values(array_diff($lineTypes, $employerTypes));

        return DB::table('payroll_earnings_lines as pel')
            ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'ps.employee_id')
            ->where('ps.payroll_run_id', $run->id)
            ->whereNull('ps.deleted_at')
            ->whereIn('pel.line_type', $lineTypes)
            ->groupBy('ps.employee_id', 'p.first_name', 'p.surname', 'p.employee_number', 'p.id_number')
            ->select(
                'ps.employee_id', 'p.first_name', 'p.surname', 'p.employee_number', 'p.id_number',
                DB::raw($this->sumCase($employeeTypes) . ' as employee_amount'),
                DB::raw($this->sumCase($employerTypes) . ' as employer_amount'),
            )
            ->havingRaw($this->sumCase($employeeTypes) . ' + ' . $this->sumCase($employerTypes) . ' <> 0')
            ->get();
    }

    /** A SUM restricted to one set of line types, or a literal zero when the set is empty. */
    private function sumCase(array $types): string
    {
        if ($types === []) {
            return '0';
        }

        $list = collect($types)->map(fn ($t) => "'" . str_replace("'", "''", $t) . "'")->implode(', ');

        return "COALESCE(SUM(CASE WHEN pel.line_type IN ({$list}) THEN pel.amount ELSE 0 END), 0)";
    }

    /** Most funds want it by a fixed day of the following month; seven days is the usual default. */
    private function dueDate(Beneficiary $beneficiary, $periodEnd): Carbon
    {
        $end = Carbon::parse($periodEnd);

        return $beneficiary->pay_by_day
            ? $end->copy()->addMonthNoOverflow()->startOfMonth()->addDays(max(0, (int) $beneficiary->pay_by_day - 1))
            : $end->copy()->addDays(7);
    }

    /** Records that a remittance has actually been paid, with the reference to prove it. */
    public function markPaid(Remittance $remittance, ?string $reference, ?int $userId = null): Remittance
    {
        $remittance->update([
            'status'            => 'paid',
            'paid_at'           => Carbon::now(),
            'payment_reference' => $reference,
            'marked_by'         => $userId,
        ]);

        return $remittance->fresh();
    }

    /**
     * The schedule a fund asks for, as CSV.
     *
     * Every fund wants a slightly different layout and most will accept this one; the point is
     * that the list exists at all, rather than being reconstructed from payslips months later.
     */
    public function toCsv(Remittance $remittance): string
    {
        $out = "Employee number,Name,ID number,Employee contribution,Employer contribution,Total\r\n";

        foreach ((array) $remittance->schedule as $row) {
            $employee = (float) ($row['employee'] ?? 0);
            $employer = (float) ($row['employer'] ?? 0);

            $out .= implode(',', [
                $this->csv($row['employee_number'] ?? ''),
                $this->csv($row['name'] ?? ''),
                $this->csv($row['id_number'] ?? ''),
                number_format($employee, 2, '.', ''),
                number_format($employer, 2, '.', ''),
                number_format($employee + $employer, 2, '.', ''),
            ]) . "\r\n";
        }

        // Quoted the same way as every other row, so a parser does not meet two shapes in one file.
        $out .= implode(',', [
            $this->csv(''), $this->csv('TOTAL'), $this->csv(''),
            number_format((float) $remittance->employee_portion, 2, '.', ''),
            number_format((float) $remittance->employer_portion, 2, '.', ''),
            number_format((float) $remittance->total, 2, '.', ''),
        ]) . "\r\n";

        return $out;
    }

    /**
     * A cell that cannot be read as a formula.
     *
     * A member number beginning with + or = is executed by Excel when the fund opens the file.
     */
    private function csv(string $value): string
    {
        $clean = str_replace('"', '""', $value);
        if ($clean !== '' && in_array($clean[0], ['=', '+', '-', '@'], true)) {
            $clean = "'" . $clean;
        }

        return '"' . $clean . '"';
    }

    /** What is owed and not yet paid, worst first — the only view that matters day to day. */
    public function outstanding()
    {
        return Remittance::query()
            ->with('beneficiary')
            ->where('status', 'due')
            ->orderBy('due_on')
            ->get();
    }
}
