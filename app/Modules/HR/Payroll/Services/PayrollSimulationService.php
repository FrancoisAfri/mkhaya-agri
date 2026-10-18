<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollSimulation;
use Illuminate\Support\Facades\DB;

class PayrollSimulationService
{
    public function simulateSalaryIncrease(array $params): PayrollSimulation
    {
        $name          = $params['name'] ?? 'Salary Increase Simulation';
        $mode          = $params['increase_mode'] ?? 'percentage';       // 'percentage' | 'fixed_amount'
        $percentage    = (float) ($params['percentage'] ?? 0);
        $fixedAmount   = (float) ($params['fixed_amount'] ?? 0);
        $employeeIds   = $params['employee_ids'] ?? 'all';
        $effectiveFrom = $params['effective_from'] ?? now()->startOfMonth()->toDateString();

        // 1. Dernière période released par employé
        $latestPeriods = DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->where('pr.status', 'released')
            ->select('ps.employee_id', DB::raw('MAX(pr.period_start) as latest_period'))
            ->groupBy('ps.employee_id');

        if ($employeeIds !== 'all') {
            $latestPeriods->whereIn('ps.employee_id', (array) $employeeIds);
        }

        // 2. Salaire correspondant à cette dernière période
        $latest = DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->joinSub($latestPeriods, 'lp', function ($join) {
                $join->on('ps.employee_id', '=', 'lp.employee_id')
                     ->on('pr.period_start', '=', 'lp.latest_period');
            })
            ->where('pr.status', 'released')
            ->select('ps.employee_id', 'ps.basic_salary')
            ->get();

        $items          = [];
        $currentTotal   = 0.0;
        $simulatedTotal = 0.0;

        foreach ($latest as $row) {
            $current   = (float) $row->basic_salary;
            $simulated = $mode === 'fixed_amount'
                ? round($current + $fixedAmount, 2)
                : round($current * (1 + $percentage / 100), 2);
            $diff      = $simulated - $current;

            $desc = $mode === 'fixed_amount'
                ? "Salary increase of R{$fixedAmount} from R{$current} to R{$simulated}"
                : "Salary increase of {$percentage}% from R{$current} to R{$simulated}";

            $currentTotal   += $current;
            $simulatedTotal += $simulated;

            $items[] = [
                'employee_id'     => $row->employee_id,
                'current_value'   => $current,
                'simulated_value' => $simulated,
                'difference'      => $diff,
                'description'     => $desc,
            ];
        }

        return $this->createSimulation(
            $name,
            ['effective_from' => $effectiveFrom, 'increase_mode' => $mode, 'percentage' => $percentage, 'fixed_amount' => $fixedAmount],
            'salary_increase',
            $currentTotal,
            $simulatedTotal,
            $items,
            auth()->id() ?? 0
        );
    }

    /**
     * Bonus simulation. Supports several bonus bases via `bonus_mode`:
     *  - fixed           : a flat amount per employee (`amount_per_employee`)
     *  - pct_gross        : a percentage of gross pay (`percentage_of_gross`)
     *  - pct_basic        : a percentage of basic salary (`percentage_of_basic`)
     *  - multiple_salary  : a multiple of basic salary (`bonus_multiple`) — 1× is a
     *                       full-salary "13th cheque", 2× is a double bonus, etc.
     */
    public function simulateBonusPayment(array $params): PayrollSimulation
    {
        $name        = $params['name'] ?? 'Bonus Payment Simulation';
        $employeeIds = $params['employee_ids'] ?? [];
        $mode        = $params['bonus_mode'] ?? 'fixed';
        $fixed       = (float) ($params['amount_per_employee'] ?? 0);
        $pctGross    = (float) ($params['percentage_of_gross'] ?? 0);
        $pctBasic    = (float) ($params['percentage_of_basic'] ?? 0);
        $multiple    = (float) ($params['bonus_multiple'] ?? 1);

        $payslips = DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->where('pr.status', 'released')
            ->when(!empty($employeeIds), fn ($q) => $q->whereIn('ps.employee_id', $employeeIds))
            ->select('ps.employee_id', 'ps.gross_pay', 'ps.basic_salary', DB::raw('MAX(pr.period_start) as latest'))
            ->groupBy('ps.employee_id', 'ps.gross_pay', 'ps.basic_salary')
            ->get();

        $items         = [];
        $currentTotal  = 0.0;
        $simulatedTotal = 0.0;

        foreach ($payslips as $row) {
            $gross = (float) $row->gross_pay;
            $basic = (float) $row->basic_salary;

            $bonus = match ($mode) {
                'pct_gross'       => round($gross * $pctGross / 100, 2),
                'pct_basic'       => round($basic * $pctBasic / 100, 2),
                'multiple_salary' => round($basic * $multiple, 2),
                default           => $fixed, // 'fixed'
            };

            $label = match ($mode) {
                'pct_gross'       => "{$pctGross}% of gross (R{$bonus})",
                'pct_basic'       => "{$pctBasic}% of basic salary (R{$bonus})",
                'multiple_salary' => rtrim(rtrim((string) $multiple, '0'), '.') . "× basic salary (R{$bonus})",
                default           => "R{$bonus} fixed",
            };

            $currentTotal   += $gross;
            $simulatedTotal += $gross + $bonus;

            $items[] = [
                'employee_id'     => $row->employee_id,
                'current_value'   => $gross,
                'simulated_value' => $gross + $bonus,
                'difference'      => $bonus,
                'description'     => "Bonus payment: {$label}",
            ];
        }

        return $this->createSimulation(
            $name,
            $params,
            'bonus_payment',
            $currentTotal,
            $simulatedTotal,
            $items,
            auth()->id() ?? 0
        );
    }

    public function simulateNewHiringCost(array $params): PayrollSimulation
    {
        $name       = $params['name'] ?? 'New Hiring Cost Simulation';
        $count      = (int) ($params['count'] ?? 1);
        $avgSalary  = (float) ($params['avg_salary'] ?? 0);
        $months     = (int) ($params['months'] ?? 1);
        $total      = $count * $avgSalary * $months;

        $items = [[
            'employee_id'     => null,
            'current_value'   => 0,
            'simulated_value' => $total,
            'difference'      => $total,
            'description'     => "New hiring: {$count} employee(s) at avg R{$avgSalary}/month for {$months} month(s)",
        ]];

        return $this->createSimulation($name, $params, 'new_hiring_cost', 0, $total, $items, auth()->id() ?? 0);
    }

    public function simulateOvertimeCost(array $params): PayrollSimulation
    {
        $name             = $params['name'] ?? 'Overtime Cost Simulation';
        $employeeIds      = $params['employee_ids'] ?? [];
        $hoursPerEmployee = (float) ($params['hours_per_employee'] ?? 0);
        $overtimeRate     = (float) ($params['overtime_rate'] ?? 0);

        $payslips = DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->where('pr.status', 'released')
            ->when(!empty($employeeIds), fn ($q) => $q->whereIn('ps.employee_id', $employeeIds))
            ->select('ps.employee_id', 'ps.gross_pay', DB::raw('MAX(pr.period_start) as latest'))
            ->groupBy('ps.employee_id', 'ps.gross_pay')
            ->get();

        $items         = [];
        $currentTotal  = 0.0;
        $simulatedTotal = 0.0;

        foreach ($payslips as $row) {
            $overtimeCost  = round($hoursPerEmployee * $overtimeRate, 2);
            $currentTotal  += (float) $row->gross_pay;
            $simulatedTotal += (float) $row->gross_pay + $overtimeCost;

            $items[] = [
                'employee_id'     => $row->employee_id,
                'current_value'   => $row->gross_pay,
                'simulated_value' => (float) $row->gross_pay + $overtimeCost,
                'difference'      => $overtimeCost,
                'description'     => "{$hoursPerEmployee} overtime hours at R{$overtimeRate}/hr = R{$overtimeCost}",
            ];
        }

        return $this->createSimulation($name, $params, 'overtime_cost', $currentTotal, $simulatedTotal, $items, auth()->id() ?? 0);
    }

    public function simulateAllowanceChange(array $params): PayrollSimulation
    {
        $name          = $params['name'] ?? 'Allowance Change Simulation';
        $employeeIds   = $params['employee_ids'] ?? [];
        $allowanceType = $params['allowance_type'] ?? 'allowance';
        $amount        = (float) ($params['amount'] ?? 0);

        $current = DB::table('payroll_earnings_lines as pel')
            ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->where('pr.status', 'released')
            ->where('pel.line_type', $allowanceType)
            ->when(!empty($employeeIds), fn ($q) => $q->whereIn('ps.employee_id', $employeeIds))
            ->select('ps.employee_id', DB::raw('SUM(pel.amount) as current_allowance'))
            ->groupBy('ps.employee_id')
            ->get();

        $items         = [];
        $currentTotal  = 0.0;
        $simulatedTotal = 0.0;

        foreach ($current as $row) {
            $currentTotal  += (float) $row->current_allowance;
            $simulatedTotal += $amount;

            $items[] = [
                'employee_id'     => $row->employee_id,
                'current_value'   => $row->current_allowance,
                'simulated_value' => $amount,
                'difference'      => $amount - (float) $row->current_allowance,
                'description'     => "Allowance type '{$allowanceType}' changed to R{$amount}",
            ];
        }

        return $this->createSimulation($name, $params, 'allowance_change', $currentTotal, $simulatedTotal, $items, auth()->id() ?? 0);
    }

    public function simulateUnionIncrease(array $params): PayrollSimulation
    {
        $name           = $params['name'] ?? 'Union Increase Simulation';
        $percentage     = (float) ($params['percentage'] ?? 0);
        $departmentIds  = $params['affected_department_ids'] ?? [];

        $query = DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->join('company_units as cu', function ($j) {
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
            })
            ->where('pr.status', 'released')
            ->when(!empty($departmentIds), fn ($q) => $q->whereIn('cu.id', $departmentIds))
            ->select('ps.employee_id', 'ps.basic_salary', DB::raw('MAX(pr.period_start) as latest'))
            ->groupBy('ps.employee_id', 'ps.basic_salary');

        $payslips      = $query->get();
        $items         = [];
        $currentTotal  = 0.0;
        $simulatedTotal = 0.0;

        foreach ($payslips as $row) {
            $current   = (float) $row->basic_salary;
            $simulated = round($current * (1 + $percentage / 100), 2);

            $currentTotal  += $current;
            $simulatedTotal += $simulated;

            $items[] = [
                'employee_id'     => $row->employee_id,
                'current_value'   => $current,
                'simulated_value' => $simulated,
                'difference'      => $simulated - $current,
                'description'     => "Union increase of {$percentage}% applied",
            ];
        }

        return $this->createSimulation($name, $params, 'union_increase', $currentTotal, $simulatedTotal, $items, auth()->id() ?? 0);
    }

    public function simulateAnnualPayrollBudget(array $params): PayrollSimulation
    {
        $name   = $params['name'] ?? 'Annual Payroll Budget Simulation';
        $year   = (int) ($params['year'] ?? now()->year);
        $buffer = (float) ($params['buffer_percentage'] ?? 15);   // contingency on top
        $growth = (float) ($params['growth_percentage'] ?? 0);    // projected headcount/cost growth

        $total = (float) DB::table('payroll_runs')
            ->where('status', 'released')
            ->whereYear('period_start', $year)
            ->sum('total_net');

        $projected  = round($total * (1 + $growth / 100), 2);
        $withBuffer = round($projected * (1 + $buffer / 100), 2);

        $items = [[
            'employee_id'     => null,
            'current_value'   => $total,
            'simulated_value' => $withBuffer,
            'difference'      => $withBuffer - $total,
            'description'     => "Annual payroll budget for {$year}: {$growth}% projected growth + {$buffer}% contingency buffer",
        ]];

        return $this->createSimulation(
            $name,
            ['year' => $year, 'growth_percentage' => $growth, 'buffer_percentage' => $buffer],
            'annual_budget',
            $total,
            $withBuffer,
            $items,
            auth()->id() ?? 0
        );
    }

    public function createSimulation(
        string $name,
        array $data,
        string $type,
        float $currentCost,
        float $simulatedCost,
        array $items,
        int $userId
    ): PayrollSimulation {
        $pctChange = $currentCost > 0
            ? round((($simulatedCost - $currentCost) / $currentCost) * 100, 4)
            : 0.0;

        $simulation = PayrollSimulation::create([
            'name'                  => $name,
            'simulation_type'       => $type,
            'parameters'            => $data,
            'total_current_cost'    => $currentCost,
            'total_simulated_cost'  => $simulatedCost,
            'total_difference'      => $simulatedCost - $currentCost,
            'percentage_change'     => $pctChange,
            'status'                => 'draft',
            'created_by'            => $userId,
        ]);

        if (!empty($items)) {
            $now    = now();
            $insert = array_map(function ($item) use ($simulation, $type, $now) {
                $curr = (float) ($item['current_value'] ?? 0);
                $sim  = (float) ($item['simulated_value'] ?? 0);
                $pct  = $curr > 0 ? round((($sim - $curr) / $curr) * 100, 4) : 0.0;

                return [
                    'simulation_id'     => $simulation->id,
                    'employee_id'       => $item['employee_id'] ?? null,
                    'department_id'     => $item['department_id'] ?? null,
                    'current_value'     => $curr,
                    'simulated_value'   => $sim,
                    'difference'        => $sim - $curr,
                    'percentage_change' => $pct,
                    'item_type'         => $type,
                    'notes'             => $item['description'] ?? $item['notes'] ?? null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }, $items);

            DB::table('payroll_simulation_items')->insert($insert);
        }

        return $simulation;
    }
}
