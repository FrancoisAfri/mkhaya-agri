<?php

namespace Database\Seeders;

use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\RuleCondition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffLoanPolicyRulesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $policy = Policy::firstOrCreate(
                ['name' => 'Staff Loan & Salary Advance Policy'],
                [
                    'uuid'        => Str::uuid(),
                    'description' => 'Governs eligibility, affordability, approval thresholds, and repayment controls for staff loans and salary advances.',
                    'status'      => 'active',
                    'date'        => now()->timestamp,
                ]
            );

            foreach ($this->rules() as $ruleDef) {
                $rule = PolicyRule::firstOrCreate(
                    ['policy_id' => $policy->id, 'name' => $ruleDef['name']],
                    [
                        'description'                 => $ruleDef['description'],
                        'severity'                    => $ruleDef['severity'],
                        'action_on_breach'            => $ruleDef['action_on_breach'],
                        'requires_exception_approval' => $ruleDef['requires_exception'] ?? false,
                        'active'                      => true,
                    ]
                );

                foreach ($ruleDef['conditions'] as $i => $condDef) {
                    RuleCondition::firstOrCreate(
                        ['rule_id' => $rule->id, 'field' => $condDef['field'], 'operator' => $condDef['operator']],
                        [
                            'type'             => $condDef['type'],
                            'value'            => $condDef['value'],
                            'value_unit'       => $condDef['value_unit'] ?? null,
                            'logical_operator' => $condDef['logical_operator'] ?? 'AND',
                            'sort_order'       => $i + 1,
                        ]
                    );
                }

                foreach ($ruleDef['event_maps'] as $mapDef) {
                    EventRuleMap::firstOrCreate(
                        ['rule_id' => $rule->id, 'module' => 'StaffLoan', 'action_pattern' => $mapDef['action_pattern']],
                        ['payload_filter' => $mapDef['payload_filter'] ?? null, 'active' => true]
                    );
                }
            }
        });
    }

    private function rules(): array
    {
        return [
            // ── Rule 1: Permanent status required ────────────────────────────
            [
                'name'               => 'Permanent Employment Required for Staff Loan',
                'description'        => 'Only permanent employees are eligible for staff loans.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => true,
                'conditions' => [
                    ['field' => 'is_permanent', 'operator' => 'eq', 'value' => false, 'type' => 'custom'],
                ],
                'event_maps' => [['action_pattern' => 'staff_loan.request.submitted']],
            ],

            // ── Rule 2: Probation must be complete ────────────────────────────
            [
                'name'               => 'Probation Must Be Completed',
                'description'        => 'Employees on probation cannot apply for staff loans or salary advances.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => false,
                'conditions' => [
                    ['field' => 'probation_completed', 'operator' => 'eq', 'value' => false, 'type' => 'custom'],
                ],
                'event_maps' => [
                    ['action_pattern' => 'staff_loan.request.submitted'],
                    ['action_pattern' => 'staff_loan.advance.submitted'],
                ],
            ],

            // ── Rule 3: Suspended employees blocked ───────────────────────────
            [
                'name'               => 'Suspended Employees Cannot Apply',
                'description'        => 'Employees under disciplinary suspension cannot apply for loans or advances.',
                'severity'           => 'critical',
                'action_on_breach'   => 'block',
                'requires_exception' => false,
                'conditions' => [
                    ['field' => 'is_suspended', 'operator' => 'eq', 'value' => true, 'type' => 'custom'],
                ],
                'event_maps' => [
                    ['action_pattern' => 'staff_loan.request.submitted'],
                    ['action_pattern' => 'staff_loan.advance.submitted'],
                ],
            ],

            // ── Rule 4: Advance ≤ 30% of net pay ─────────────────────────────
            [
                'name'               => 'Salary Advance Limited to 30% of Net Pay',
                'description'        => 'A salary advance cannot exceed 30% of the employee\'s monthly net pay.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => true,
                'conditions' => [
                    ['field' => 'percentage_of_net', 'operator' => 'gt', 'value' => 30, 'value_unit' => 'percent', 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'staff_loan.advance.submitted']],
            ],

            // ── Rule 5: No more than one active advance ───────────────────────
            [
                'name'               => 'Only One Active Salary Advance Allowed',
                'description'        => 'An employee cannot have more than one active salary advance at a time.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => false,
                'conditions' => [
                    ['field' => 'active_advance_count', 'operator' => 'gte', 'value' => 1, 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'staff_loan.advance.submitted']],
            ],

            // ── Rule 6: Loan repayment ≤ 25% of net pay ──────────────────────
            [
                'name'               => 'Loan Repayment Cannot Exceed 25% of Net Pay',
                'description'        => 'Total monthly loan deductions (existing + proposed) cannot exceed 25% of net pay.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => true,
                'conditions' => [
                    ['field' => 'repayment_affordability_ratio', 'operator' => 'gt', 'value' => 25, 'value_unit' => 'percent', 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'staff_loan.request.submitted']],
            ],

            // ── Rule 7: Executive approval for loans > R20,000 ───────────────
            [
                'name'               => 'Executive Approval for Loans Above R20,000',
                'description'        => 'Loan requests above R20,000 must be escalated for executive approval.',
                'severity'           => 'medium',
                'action_on_breach'   => 'escalate',
                'requires_exception' => false,
                'conditions' => [
                    ['field' => 'amount_requested', 'operator' => 'gt', 'value' => 20000, 'value_unit' => 'ZAR', 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'staff_loan.request.submitted']],
            ],

            // ── Rule 8: Overdue repayments escalation ─────────────────────────
            [
                'name'               => 'Overdue Loan Repayments Require Escalation',
                'description'        => 'Loans with 3 or more overdue installments must be escalated to HR and Finance.',
                'severity'           => 'high',
                'action_on_breach'   => 'escalate',
                'requires_exception' => false,
                'conditions' => [
                    ['field' => 'overdue_count', 'operator' => 'gte', 'value' => 3, 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'staff_loan.repayment.overdue']],
            ],
        ];
    }
}
