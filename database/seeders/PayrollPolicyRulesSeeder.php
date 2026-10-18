<?php

namespace Database\Seeders;

use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\RuleCondition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollPolicyRulesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $policy = Policy::firstOrCreate(
                ['name' => 'Payroll Integrity & Risk Policy'],
                [
                    'uuid'        => Str::uuid(),
                    'description' => 'Governs payroll run submission, high-risk detection, anomaly thresholds, ghost employee detection, and release controls.',
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
                        ['rule_id' => $rule->id, 'module' => 'Payroll', 'action_pattern' => $mapDef['action_pattern']],
                        ['payload_filter' => $mapDef['payload_filter'] ?? null, 'active' => true]
                    );
                }
            }
        });
    }

    private function rules(): array
    {
        return [
            // ── Rule 1: Ghost employee detected ───────────────────────────────
            [
                'name'               => 'Ghost Employee Detected',
                'description'        => 'Blocks payroll submission when ghost or terminated employee anomalies are present.',
                'severity'           => 'critical',
                'action_on_breach'   => 'block',
                'requires_exception' => true,
                'conditions'         => [
                    ['field' => 'anomaly_count', 'operator' => 'gt', 'value' => 0, 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'payroll_run.submitted']],
            ],

            // ── Rule 2: Payroll above budget ──────────────────────────────────
            [
                'name'               => 'Payroll Above Budget',
                'description'        => 'Escalates payroll runs where total net pay exceeds R500,000.',
                'severity'           => 'high',
                'action_on_breach'   => 'escalate',
                'requires_exception' => false,
                'conditions'         => [
                    ['field' => 'total_net', 'operator' => 'gt', 'value' => 500000, 'value_unit' => 'ZAR', 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'payroll_run.submitted']],
            ],

            // ── Rule 3: High risk payroll requires executive approval ──────────
            [
                'name'               => 'High Risk Payroll Requires Executive Approval',
                'description'        => 'Escalates payroll runs with a critical AI risk level for executive sign-off.',
                'severity'           => 'high',
                'action_on_breach'   => 'escalate',
                'requires_exception' => false,
                'conditions'         => [
                    ['field' => 'risk_level', 'operator' => 'eq', 'value' => 'critical', 'type' => 'status'],
                ],
                'event_maps' => [['action_pattern' => 'payroll_run.high_risk']],
            ],

            // ── Rule 4: Terminated employee in payroll ────────────────────────
            [
                'name'               => 'Terminated Employee in Payroll',
                'description'        => 'Blocks submission when the payroll run contains a terminated employee.',
                'severity'           => 'critical',
                'action_on_breach'   => 'block',
                'requires_exception' => true,
                'conditions'         => [
                    ['field' => 'has_terminated_employees', 'operator' => 'eq', 'value' => 'true', 'type' => 'custom'],
                ],
                'event_maps' => [['action_pattern' => 'payroll_run.submitted']],
            ],

            // ── Rule 5: Payroll with duplicate payments ───────────────────────
            [
                'name'               => 'Payroll With Duplicate Payments',
                'description'        => 'Warns when more than 2 open anomalies are detected in a payroll run.',
                'severity'           => 'high',
                'action_on_breach'   => 'warn',
                'requires_exception' => false,
                'conditions'         => [
                    ['field' => 'anomaly_count', 'operator' => 'gt', 'value' => 2, 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'payroll_run.submitted']],
            ],

            // ── Rule 6: Critical anomalies block release ──────────────────────
            [
                'name'               => 'Critical Anomalies Block Release',
                'description'        => 'Blocks payroll release when any open anomalies remain unresolved.',
                'severity'           => 'critical',
                'action_on_breach'   => 'block',
                'requires_exception' => false,
                'conditions'         => [
                    ['field' => 'anomaly_count', 'operator' => 'gt', 'value' => 0, 'type' => 'numeric'],
                ],
                'event_maps' => [['action_pattern' => 'payroll_run.released']],
            ],
        ];
    }
}
