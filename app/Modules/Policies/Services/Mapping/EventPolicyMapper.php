<?php

namespace App\Modules\Policies\Services\Mapping;

use App\Modules\Policies\Models\{Policy, PolicyRule, EventRuleMap};
use App\Modules\Policies\DTOs\PolicyEventDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EventPolicyMapper
 * 
 * Manages the mapping between events and policies/rules.
 * Allows querying:
 * - Which policies apply to an event
 * - Which rules apply to an event
 * - Which events trigger a policy
 * - Creating/updating/removing event mappings
 */
class EventPolicyMapper
{
    /**
     * Get all policies applicable to a module
     * 
     * @param string $module Module name (e.g., 'Leave', 'Claims')
     * @return Collection
     */
    public function getPoliciesForModule(string $module): Collection
    {
        return Policy::where('status', 'active')
            ->where('module', $module)
            ->with(['clauses', 'rules.eventMaps'])
            ->get();
    }

    /**
     * Get all rules that apply to a specific event
     * 
     * @param PolicyEventDTO $event
     * @return Collection PolicyRule objects
     */
    public function getRulesForEvent(PolicyEventDTO $event): Collection
    {
        return EventRuleMap::with('rule.policy', 'rule.conditions', 'rule.actions')
            ->where('module', $event->module)
            ->where('active', true)
            ->get()
            ->filter(function (EventRuleMap $map) use ($event) {
                // Match action pattern (supports wildcards: 'leave.*', 'leave_request.*')
                if (!fnmatch($map->action_pattern, $event->action)) {
                    return false;
                }

                // Check payload filter if specified
                return $this->passesPayloadFilter($map->payload_filter, $event->payload);
            })
            ->map(fn(EventRuleMap $map) => $map->rule)
            ->filter(fn(?PolicyRule $rule) => $rule && $rule->active && $rule->policy->status === 'active')
            ->unique('id')
            ->values();
    }

    /**
     * Get all applicable policies for an event
     * 
     * @param PolicyEventDTO $event
     * @return Collection Policy objects
     */
    public function getPoliciesForEvent(PolicyEventDTO $event): Collection
    {
        return $this->getRulesForEvent($event)
            ->map(fn(PolicyRule $rule) => $rule->policy)
            ->unique('id')
            ->values();
    }

    /**
     * Get all events that trigger a specific policy
     * 
     * @param int $policyId
     * @return Collection
     */
    public function getEventsForPolicy(int $policyId): Collection
    {
        return EventRuleMap::whereHas('rule', function ($query) use ($policyId) {
            $query->where('policy_id', $policyId);
        })
            ->where('active', true)
            ->get()
            ->map(function (EventRuleMap $map) {
                return [
                    'module' => $map->module,
                    'action_pattern' => $map->action_pattern,
                    'rule_id' => $map->rule_id,
                    'rule_name' => $map->rule->name,
                    'enforcement_action' => $map->rule->action_on_breach,
                ];
            });
    }

    /**
     * Get all rules for a specific policy
     * 
     * @param int $policyId
     * @return Collection
     */
    public function getRulesForPolicy(int $policyId): Collection
    {
        return PolicyRule::where('policy_id', $policyId)
            ->where('active', true)
            ->with(['eventMaps', 'conditions', 'actions'])
            ->get();
    }

    /**
     * Get event mappings for a rule
     * 
     * @param int $ruleId
     * @return Collection
     */
    public function getMappingsForRule(int $ruleId): Collection
    {
        return EventRuleMap::where('rule_id', $ruleId)
            ->where('active', true)
            ->get();
    }

    /**
     * Map a rule to an event
     * 
     * @param int $ruleId
     * @param string $module
     * @param string $actionPattern
     * @param array|null $payloadFilter
     * @return EventRuleMap
     */
    public function mapRuleToEvent(
        int $ruleId,
        string $module,
        string $actionPattern,
        ?array $payloadFilter = null
    ): EventRuleMap {
        return EventRuleMap::updateOrCreate(
            [
                'rule_id' => $ruleId,
                'module' => $module,
                'action_pattern' => $actionPattern,
            ],
            [
                'payload_filter' => $payloadFilter,
                'active' => true,
            ]
        );
    }

    /**
     * Unmap a rule from an event
     * 
     * @param int $ruleId
     * @param string $module
     * @param string $actionPattern
     * @return bool
     */
    public function unmapRuleFromEvent(int $ruleId, string $module, string $actionPattern): bool
    {
        $mapping = EventRuleMap::where('rule_id', $ruleId)
            ->where('module', $module)
            ->where('action_pattern', $actionPattern)
            ->first();

        if (!$mapping) {
            return false;
        }

        return $mapping->delete();
    }

    /**
     * Deactivate a mapping without deleting it
     * 
     * @param int $mappingId
     * @return bool
     */
    public function deactivateMapping(int $mappingId): bool
    {
        return EventRuleMap::findOrFail($mappingId)->update(['active' => false]);
    }

    /**
     * Reactivate a mapping
     * 
     * @param int $mappingId
     * @return bool
     */
    public function reactivateMapping(int $mappingId): bool
    {
        return EventRuleMap::findOrFail($mappingId)->update(['active' => true]);
    }

    /**
     * Get detailed mapping report for a module
     * Shows which events trigger which policies/rules
     * 
     * @param string $module
     * @return array
     */
    public function getMappingReportForModule(string $module): array
    {
        $mappings = EventRuleMap::where('module', $module)
            ->where('active', true)
            ->with('rule.policy', 'rule.conditions')
            ->get();

        return $mappings
            ->groupBy('action_pattern')
            ->map(function ($group, $actionPattern) use ($module) {
                return [
                    'module' => $module,
                    'action_pattern' => $actionPattern,
                    'rules_count' => $group->count(),
                    'rules' => $group->map(function (EventRuleMap $map) {
                        return [
                            'rule_id' => $map->rule->id,
                            'rule_name' => $map->rule->name,
                            'policy_id' => $map->rule->policy->id,
                            'policy_name' => $map->rule->policy->name,
                            'enforcement_action' => $map->rule->action_on_breach,
                            'conditions_count' => $map->rule->conditions->count(),
                            'payload_filter' => $map->payload_filter,
                        ];
                    })->toArray(),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get summary of all event mappings across all modules
     * 
     * @return array
     */
    public function getGlobalMappingSummary(): array
    {
        $allMappings = EventRuleMap::where('active', true)
            ->with('rule.policy')
            ->get();

        $modules = $allMappings->groupBy('module');

        return [
            'total_mappings' => $allMappings->count(),
            'modules' => $allMappings->pluck('module')->unique()->values()->toArray(),
            'by_module' => $modules
                ->map(function ($moduleMappings, $moduleName) {
                    return [
                        'module' => $moduleName,
                        'mappings_count' => $moduleMappings->count(),
                        'unique_actions' => $moduleMappings->pluck('action_pattern')->unique()->count(),
                        'policies_count' => $moduleMappings->map(fn($m) => $m->rule->policy_id)->unique()->count(),
                        'rules_count' => $moduleMappings->map(fn($m) => $m->rule_id)->unique()->count(),
                    ];
                })
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Test if an event would trigger any rules
     * 
     * @param PolicyEventDTO $event
     * @return array Mapping results
     */
    public function testEventMapping(PolicyEventDTO $event): array
    {
        $applicableRules = $this->getRulesForEvent($event);
        $applicablePolicies = $this->getPoliciesForEvent($event);

        return [
            'event' => [
                'module' => $event->module,
                'action' => $event->action,
            ],
            'matches' => [
                'policies' => $applicablePolicies->map(fn(Policy $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'module' => $p->module,
                ])->toArray(),
                'rules' => $applicableRules->map(fn(PolicyRule $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'enforcement_action' => $r->action_on_breach,
                    'conditions_count' => $r->conditions->count(),
                ])->toArray(),
            ],
            'summary' => [
                'policies_matching' => $applicablePolicies->count(),
                'rules_matching' => $applicableRules->count(),
                'will_trigger_enforcement' => $applicableRules->isNotEmpty(),
            ],
        ];
    }

    /**
     * Validate payload filter matches event payload
     * 
     * @param array|null $filter
     * @param array $payload
     * @return bool
     */
    private function passesPayloadFilter(?array $filter, array $payload): bool
    {
        if (empty($filter)) {
            return true;
        }

        foreach ($filter as $key => $expectedValue) {
            $actualValue = data_get($payload, $key);
            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cascade information - which rules depend on which policies
     * 
     * @return array
     */
    public function getDependencyMap(): array
    {
        return Policy::where('status', 'active')
            ->with(['rules.eventMaps'])
            ->get()
            ->map(function (Policy $policy) {
                return [
                    'policy_id' => $policy->id,
                    'policy_name' => $policy->name,
                    'policy_module' => $policy->module,
                    'rules_count' => $policy->rules->count(),
                    'event_mappings' => $policy->rules
                        ->flatMap(fn(PolicyRule $rule) => $rule->eventMaps)
                        ->map(fn(EventRuleMap $map) => [
                            'module' => $map->module,
                            'action_pattern' => $map->action_pattern,
                        ])
                        ->toArray(),
                ];
            })
            ->toArray();
    }
}
