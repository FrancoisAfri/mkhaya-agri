<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\Budget\Services\BudgetLedgerBridge;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tell the budget what a released payroll run cost, department by department.
 *
 * Payroll is usually the largest operating line a company has, and until now the budget module
 * never heard about it: "available" counted purchase orders and nothing else, so every department
 * budget in the product looked healthier than it was and a blocking rule set at 90% would not trip
 * until the year was nearly over.
 *
 * Split by department rather than posted as one lump, because a single company-wide figure tells a
 * departmental budget nothing. The split follows the same path the rest of the platform uses for
 * departments — `company_unit_user_pivots` joined to `company_units` — and anything that cannot be
 * resolved falls to the general budget rather than being dropped.
 *
 * Gross pay is the measure. It is what the employer commits to the employee and the closest figure
 * on the run to an employment cost; net pay would understate it by every deduction, which is
 * precisely the money the company still pays out, just to somebody else.
 */
class PayrollBudgetPoster
{
    public function __construct(private readonly BudgetLedgerBridge $bridge)
    {
    }

    /**
     * @return array{posted:int, unmapped:int, duplicate:int}
     */
    public function postRun(PayrollRun $run): array
    {
        $summary = ['posted' => 0, 'unmapped' => 0, 'duplicate' => 0];

        if (! $this->bridge->enabled()) {
            return $summary;
        }

        $when = $run->period_end ? Carbon::parse($run->period_end) : now();

        foreach ($this->grossByDepartment($run) as $departmentId => $gross) {
            $result = $this->bridge->postSpend(
                sourceType: 'payroll_run',
                sourceId: (int) $run->id,
                amount: (float) $gross,
                when: $when,
                // A null department means the general budget, which is what the resolver falls
                // back to on its own.
                scope: ['department_id' => $departmentId ?: null, 'expenditure_type' => 'opex'],
                // One posting per run per department, enforced by the database. Re-releasing a
                // corrected run cannot charge the budget twice.
                reference: 'payroll_run:'.$run->id.':dept:'.($departmentId ?: 'none'),
                note: __('budget_management.sources.payroll', ['run' => $run->name ?? $run->ref_number ?? $run->id]),
            );

            match ($result->outcome) {
                'posted'    => $summary['posted']++,
                'duplicate' => $summary['duplicate']++,
                default     => $summary['unmapped']++,
            };
        }

        return $summary;
    }

    /**
     * Gross pay grouped by the department each employee belongs to.
     *
     * `company_unit_user_pivots.company_id` holds a *company unit* id despite its name — the
     * platform-wide quirk this join has to live with. Employees with no department land under key 0
     * and are posted to the general budget.
     *
     * @return array<int, float>
     */
    private function grossByDepartment(PayrollRun $run): array
    {
        $rows = DB::table('payslips as ps')
            ->leftJoin('hr_people as p', 'p.id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as piv', 'piv.user_id', '=', 'p.user_id')
            ->leftJoin('company_units as u', function ($join) {
                $join->on('u.id', '=', 'piv.company_id')->where('u.type', '=', 'department');
            })
            ->where('ps.payroll_run_id', $run->id)
            ->whereNull('ps.deleted_at')
            ->groupBy('u.id')
            ->selectRaw('u.id as department_id, sum(ps.gross_pay) as gross')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $key = (int) ($row->department_id ?? 0);
            $out[$key] = ($out[$key] ?? 0) + (float) $row->gross;
        }

        return array_filter($out, fn ($gross) => $gross > 0);
    }
}
