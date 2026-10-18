<?php

namespace Database\Seeders;

use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\RuleCondition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PettyCashPolicyRulesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $policy = Policy::firstOrCreate(
                ['name' => 'Petty Cash Management Policy'],
                [
                    'uuid'        => Str::uuid(),
                    'description' => 'Governs the request, issuance, reconciliation and replenishment of petty cash floats.',
                    'status'      => 'active',
                    'date'        => now()->timestamp,
                ]
            );

            foreach ($this->rules() as $ruleDef) {
                $rule = PolicyRule::firstOrCreate(
                    [
                        'policy_id' => $policy->id,
                        'name'      => $ruleDef['name'],
                    ],
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
                        [
                            'rule_id'  => $rule->id,
                            'field'    => $condDef['field'],
                            'operator' => $condDef['operator'],
                        ],
                        [
                            'type'             => $condDef['type'] ?? 'payload',
                            'value'            => $condDef['value'],
                            'value_unit'       => $condDef['value_unit'] ?? null,
                            'logical_operator' => $condDef['logical_operator'] ?? 'AND',
                            'sort_order'       => $i + 1,
                        ]
                    );
                }

                foreach ($ruleDef['event_maps'] as $mapDef) {
                    EventRuleMap::firstOrCreate(
                        [
                            'rule_id'        => $rule->id,
                            'module'         => 'PettyCash',
                            'action_pattern' => $mapDef['action_pattern'],
                        ],
                        [
                            'payload_filter' => $mapDef['payload_filter'] ?? null,
                            'active'         => true,
                        ]
                    );
                }
            }
        });
    }

    private function rules(): array
    {
        return [
            // ── Rule 1: Single request amount cap ─────────────────────────────
            [
                'name'               => 'Single Petty Cash Request Limit',
                'description'        => 'A single petty cash request cannot exceed R1,000.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => true,
                'conditions'         => [
                    [
                        'field'     => 'amount_requested',
                        'operator'  => 'gt',
                        'value'     => 1000,
                        'value_unit'=> 'ZAR',
                        'type'      => 'numeric',
                    ],
                ],
                'event_maps' => [
                    ['action_pattern' => 'petty_cash.request.submitted'],
                ],
            ],

            // ── Rule 2: Prohibited purposes ───────────────────────────────────
            [
                'name'               => 'Prohibited Petty Cash Purpose',
                'description'        => 'Petty cash cannot be used for salaries, loans, wages, or payroll-related payments.',
                'severity'           => 'critical',
                'action_on_breach'   => 'block',
                'requires_exception' => false,
                'conditions'         => [
                    [
                        'field'    => 'purpose_is_prohibited',
                        'operator' => 'eq',
                        'value'    => true,
                        'type'     => 'custom',
                    ],
                ],
                'event_maps' => [
                    ['action_pattern' => 'petty_cash.request.submitted'],
                ],
            ],

            // ── Rule 3: Receipt deadline ──────────────────────────────────────
            [
                'name'               => 'Petty Cash Receipt Submission Deadline',
                'description'        => 'Receipts must be uploaded within 48 hours of cash issuance. Late submissions trigger a warning.',
                'severity'           => 'medium',
                'action_on_breach'   => 'warn',
                'requires_exception' => false,
                'conditions'         => [
                    [
                        'field'    => 'receipt_submitted',
                        'operator' => 'eq',
                        'value'    => false,
                        'type'     => 'status',
                    ],
                    [
                        'field'           => 'hours_since_issue',
                        'operator'        => 'gt',
                        'value'           => 48,
                        'value_unit'      => 'hours',
                        'logical_operator'=> 'AND',
                        'type'            => 'numeric',
                    ],
                ],
                'event_maps' => [
                    ['action_pattern' => 'petty_cash.cash.issued'],
                ],
            ],

            // ── Rule 4: Custodian self-reconciliation ─────────────────────────
            [
                'name'               => 'Custodian Cannot Self-Reconcile',
                'description'        => 'A custodian cannot submit and approve their own float reconciliation. A separate approver is required.',
                'severity'           => 'high',
                'action_on_breach'   => 'block',
                'requires_exception' => false,
                'conditions'         => [
                    [
                        'field'    => 'self_reconciliation',
                        'operator' => 'eq',
                        'value'    => true,
                        'type'     => 'custom',
                    ],
                ],
                'event_maps' => [
                    ['action_pattern' => 'petty_cash.reconciliation.submitted'],
                ],
            ],

            // ── Rule 5: Float shortage escalation ────────────────────────────
            [
                'name'               => 'Float Shortage Requires Finance Escalation',
                'description'        => 'When a float balance drops at or below the replenishment threshold, the finance team must be notified and the shortage escalated.',
                'severity'           => 'high',
                'action_on_breach'   => 'escalate',
                'requires_exception' => false,
                'conditions'         => [
                    [
                        'field'     => 'utilization_pct',
                        'operator'  => 'gte',
                        'value'     => 70,
                        'value_unit'=> 'percent',
                        'type'      => 'numeric',
                    ],
                ],
                'event_maps' => [
                    ['action_pattern' => 'petty_cash.float.shortage'],
                ],
            ],

            // ── Rule 6: Repeated requests trigger investigation ───────────────
            [
                'name'               => 'Repeated Petty Cash Requests Trigger Investigation',
                'description'        => 'A requestor submitting 3 or more petty cash requests within 7 days for the same float triggers an investigation flag.',
                'severity'           => 'medium',
                'action_on_breach'   => 'escalate',
                'requires_exception' => false,
                'conditions'         => [
                    [
                        'field'    => 'recent_request_count',
                        'operator' => 'gte',
                        'value'    => 3,
                        'type'     => 'numeric',
                    ],
                ],
                'event_maps' => [
                    ['action_pattern' => 'petty_cash.request.submitted'],
                ],
            ],
        ];
    }
}
