<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PayrollAnomalyRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name'             => 'Duplicate Payment',
                'description'      => 'Flags an employee who appears more than once in the same payroll run.',
                'anomaly_type'     => 'duplicate_payment',
                'threshold_type'   => 'count',
                'threshold_value'  => 1,  // > 1 occurrence triggers
                'severity'         => 'high',
                'action_on_breach' => 'block',
                'applies_to'       => 'run',
                'active'           => true,
            ],
            [
                'name'             => 'Inactive Employee Paid',
                'description'      => 'Flags employees included in payroll whose HR status is not active (status != 1).',
                'anomaly_type'     => 'inactive_employee_paid',
                'threshold_type'   => 'count',
                'threshold_value'  => 0,
                'severity'         => 'high',
                'action_on_breach' => 'warn',
                'applies_to'       => 'employee',
                'active'           => true,
            ],
            [
                'name'             => 'Terminated Employee Paid',
                'description'      => 'Flags employees paid after their termination date (date_left).',
                'anomaly_type'     => 'terminated_employee_paid',
                'threshold_type'   => 'count',
                'threshold_value'  => 0,
                'severity'         => 'critical',
                'action_on_breach' => 'block',
                'applies_to'       => 'employee',
                'active'           => true,
            ],
            [
                'name'             => 'Unusual Overtime',
                'description'      => 'Flags overtime exceeding a percentage of basic salary.',
                'anomaly_type'     => 'unusual_overtime',
                'threshold_type'   => 'percentage',
                'threshold_value'  => 50, // 50% of basic salary
                'severity'         => 'medium',
                'action_on_breach' => 'flag',
                'applies_to'       => 'item',
                'active'           => true,
            ],
            [
                'name'             => 'Unauthorized Salary Increase',
                'description'      => 'Flags salary increases above the defined threshold vs the previous released run.',
                'anomaly_type'     => 'unauthorized_salary_increase',
                'threshold_type'   => 'percentage',
                'threshold_value'  => 15, // 15% increase threshold
                'severity'         => 'high',
                'action_on_breach' => 'escalate',
                'applies_to'       => 'employee',
                'active'           => true,
            ],
            [
                'name'             => 'Duplicate Bank Account',
                'description'      => 'Flags two or more employees sharing the same bank account number.',
                'anomaly_type'     => 'duplicate_bank_account',
                'threshold_type'   => 'count',
                'threshold_value'  => 1,
                'severity'         => 'critical',
                'action_on_breach' => 'block',
                'applies_to'       => 'run',
                'active'           => true,
            ],
            [
                'name'             => 'Negative Net Pay',
                'description'      => 'Flags payslips with a negative net pay after deductions.',
                'anomaly_type'     => 'negative_net_pay',
                'threshold_type'   => 'fixed',
                'threshold_value'  => 0,
                'severity'         => 'high',
                'action_on_breach' => 'warn',
                'applies_to'       => 'item',
                'active'           => true,
            ],
            [
                'name'             => 'Payroll Above Budget',
                'description'      => 'Flags the payroll run when total net pay exceeds the configured budget.',
                'anomaly_type'     => 'payroll_above_budget',
                'threshold_type'   => 'fixed',
                'threshold_value'  => 0,  // uses payroll_settings.payroll_budget
                'severity'         => 'high',
                'action_on_breach' => 'escalate',
                'applies_to'       => 'run',
                'active'           => true,
            ],
            [
                'name'             => 'Late Payroll Change',
                'description'      => 'Flags payroll changes recorded after the period end date.',
                'anomaly_type'     => 'late_payroll_change',
                'threshold_type'   => 'count',
                'threshold_value'  => 0,
                'severity'         => 'medium',
                'action_on_breach' => 'flag',
                'applies_to'       => 'run',
                'active'           => true,
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('payroll_anomaly_rules')->updateOrInsert(
                ['anomaly_type' => $rule['anomaly_type']],
                array_merge($rule, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
