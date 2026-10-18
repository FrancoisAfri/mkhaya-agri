<?php
namespace App\Modules\Policies\Actions\PolicyRule;
use App\Modules\Policies\Models\{PolicyRule,RuleCondition,RuleAction,EventRuleMap};
use Illuminate\Support\Facades\DB;
class CreateRuleAction {
    public function execute(int $policyId, array $data, int $createdBy): PolicyRule {
        return DB::transaction(function () use ($policyId, $data, $createdBy) {
            $rule = PolicyRule::create([
                'policy_id'                    => $policyId,
                'clause_id'                    => $data['clause_id'] ?? null,
                'name'                         => $data['name'],
                'description'                  => $data['description'] ?? null,
                'natural_language_input'        => $data['natural_language_input'] ?? null,
                'severity'                     => $data['severity'],
                'action_on_breach'              => $data['action_on_breach'],
                'requires_exception_approval'   => $data['requires_exception_approval'] ?? false,
                'active'                       => $data['active'] ?? true,
                'created_by'                   => $createdBy,
            ]);

            foreach ($data['conditions'] as $i => $c) {
                RuleCondition::create([
                    'rule_id'          => $rule->id,
                    'type'             => $c['type'],
                    'field'            => $c['field'],
                    'operator'         => $c['operator'],
                    'value'            => $c['value'],
                    'value_unit'       => $c['value_unit'] ?? null,
                    'logical_operator' => $c['logical_operator'] ?? 'AND',
                    'sort_order'       => $i,
                ]);
            }

            foreach ($data['actions'] as $i => $a) {
                RuleAction::create([
                    'rule_id'          => $rule->id,
                    'type'             => $a['type'],
                    'config'           => $a['config'] ?? null,
                    'message_template' => $a['message_template'] ?? null,
                    'sort_order'       => $i,
                ]);
            }

            foreach ($data['event_maps'] ?? [] as $m) {
                EventRuleMap::create([
                    'rule_id'        => $rule->id,
                    'module'         => $m['module'],
                    'action_pattern' => $m['action_pattern'],
                    'payload_filter' => $m['payload_filter'] ?? null,
                    'active'         => true,
                ]);
            }

            return $rule->load(['conditions','actions','eventMaps']);
        });
    }
}
