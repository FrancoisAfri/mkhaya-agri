<?php

namespace App\Modules\Policies\Http\Controllers;

use App\Modules\Policies\Services\Mapping\{EventPolicyMapper, RuleDiscoveryEngine};
use App\Modules\Policies\Models\{PolicyRule, EventRuleMap};
use App\Modules\Policies\DTOs\PolicyEventDTO;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EventMappingController
 * 
 * Admin interface for managing event-to-policy-to-rules mappings.
 * Provides endpoints to:
 * - View current mappings
 * - Create new mappings
 * - Edit existing mappings
 * - Delete/deactivate mappings
 * - Test mapping logic
 * - Get mapping reports
 */
class EventMappingController
{
    public function __construct(
        private readonly EventPolicyMapper $policyMapper,
        private readonly RuleDiscoveryEngine $discoveryEngine,
    ) {}

    /**
     * Get all event mappings
     * GET /api/event-mappings
     */
    public function index(Request $request)
    {
        $module = $request->query('module');
        $action = $request->query('action');

        $query = EventRuleMap::with(['rule.policy', 'rule.conditions', 'rule.actions']);

        if ($module) {
            $query->where('module', $module);
        }

        if ($action) {
            $query->where('action_pattern', $action);
        }

        return response()->json([
            'mappings' => $query->get(),
            'total' => $query->count(),
        ]);
    }

    /**
     * Get mapping report for a specific module
     * GET /api/event-mappings/report/module/{module}
     */
    public function reportForModule($module)
    {
        $report = $this->policyMapper->getMappingReportForModule($module);

        return response()->json([
            'module' => $module,
            'report' => $report,
            'summary' => [
                'total_actions' => count($report),
                'total_rules' => collect($report)->sum('rules_count'),
            ],
        ]);
    }

    /**
     * Get global mapping summary
     * GET /api/event-mappings/summary
     */
    public function summary()
    {
        return response()->json($this->policyMapper->getGlobalMappingSummary());
    }

    /**
     * Get available modules
     * GET /api/event-mappings/modules
     */
    public function getModules()
    {
        return response()->json([
            'modules' => $this->discoveryEngine->getModulesWithMappings(),
        ]);
    }

    /**
     * Get action patterns for a module
     * GET /api/event-mappings/modules/{module}/actions
     */
    public function getActionsForModule($module)
    {
        return response()->json([
            'module' => $module,
            'actions' => $this->discoveryEngine->getActionPatternsForModule($module),
        ]);
    }

    /**
     * Create a new event-rule mapping
     * POST /api/event-mappings
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'rule_id' => 'required|exists:policy_rules,id',
            'module' => 'required|string',
            'action_pattern' => 'required|string',
            'payload_filter' => 'nullable|array',
        ]);

        $mapping = $this->policyMapper->mapRuleToEvent(
            $validated['rule_id'],
            $validated['module'],
            $validated['action_pattern'],
            $validated['payload_filter']
        );

        // Clear cache
        $this->discoveryEngine->clearCacheForEvent($validated['module'], $validated['action_pattern']);

        return response()->json([
            'success' => true,
            'mapping' => $mapping->load('rule.policy'),
            'message' => 'Event-rule mapping created successfully.',
        ], 201);
    }

    /**
     * Get a specific mapping
     * GET /api/event-mappings/{id}
     */
    public function show($id)
    {
        $mapping = EventRuleMap::with(['rule.policy', 'rule.conditions', 'rule.actions'])
            ->findOrFail($id);

        return response()->json($mapping);
    }

    /**
     * Update a mapping
     * PUT /api/event-mappings/{id}
     */
    public function update(Request $request, $id)
    {
        $mapping = EventRuleMap::findOrFail($id);

        $validated = $request->validate([
            'action_pattern' => 'sometimes|string',
            'payload_filter' => 'nullable|array',
            'active' => 'sometimes|boolean',
        ]);

        $oldModule = $mapping->module;
        $oldAction = $mapping->action_pattern;

        $mapping->update($validated);

        // Clear cache for old and new action patterns
        $this->discoveryEngine->clearCacheForEvent($oldModule, $oldAction);
        if (isset($validated['action_pattern'])) {
            $this->discoveryEngine->clearCacheForEvent($mapping->module, $validated['action_pattern']);
        }

        return response()->json([
            'success' => true,
            'mapping' => $mapping->load('rule.policy'),
            'message' => 'Mapping updated successfully.',
        ]);
    }

    /**
     * Deactivate a mapping without deleting
     * PATCH /api/event-mappings/{id}/deactivate
     */
    public function deactivate($id)
    {
        $mapping = EventRuleMap::findOrFail($id);
        $this->policyMapper->deactivateMapping($id);

        // Clear cache
        $this->discoveryEngine->clearCacheForEvent($mapping->module, $mapping->action_pattern);

        return response()->json([
            'success' => true,
            'message' => 'Mapping deactivated.',
        ]);
    }

    /**
     * Reactivate a mapping
     * PATCH /api/event-mappings/{id}/activate
     */
    public function activate($id)
    {
        $mapping = EventRuleMap::findOrFail($id);
        $this->policyMapper->reactivateMapping($id);

        // Clear cache
        $this->discoveryEngine->clearCacheForEvent($mapping->module, $mapping->action_pattern);

        return response()->json([
            'success' => true,
            'message' => 'Mapping reactivated.',
        ]);
    }

    /**
     * Delete a mapping
     * DELETE /api/event-mappings/{id}
     */
    public function destroy($id)
    {
        $mapping = EventRuleMap::findOrFail($id);
        $module = $mapping->module;
        $action = $mapping->action_pattern;

        $mapping->delete();

        // Clear cache
        $this->discoveryEngine->clearCacheForEvent($module, $action);

        return response()->json([
            'success' => true,
            'message' => 'Mapping deleted.',
        ]);
    }

    /**
     * Test event mapping logic
     * POST /api/event-mappings/test
     */
    public function testMapping(Request $request)
    {
        $validated = $request->validate([
            'module' => 'required|string',
            'action' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        $event = new PolicyEventDTO(
            module: $validated['module'],
            action: $validated['action'],
            payload: $validated['payload'] ?? [],
        );

        return response()->json(
            $this->policyMapper->testEventMapping($event)
        );
    }

    /**
     * Get all rules for a policy
     * GET /api/event-mappings/policy/{policyId}/rules
     */
    public function getRulesForPolicy($policyId)
    {
        $rules = $this->policyMapper->getRulesForPolicy($policyId);

        return response()->json([
            'policy_id' => $policyId,
            'rules' => $rules->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'enforcement_action' => $rule->action_on_breach,
                    'event_mappings' => $rule->eventMaps->map(fn($m) => [
                        'module' => $m->module,
                        'action_pattern' => $m->action_pattern,
                        'payload_filter' => $m->payload_filter,
                    ])->toArray(),
                ];
            }),
        ]);
    }

    /**
     * Get events for a policy
     * GET /api/event-mappings/policy/{policyId}/events
     */
    public function getEventsForPolicy($policyId)
    {
        $events = $this->policyMapper->getEventsForPolicy($policyId);

        return response()->json([
            'policy_id' => $policyId,
            'events' => $events->toArray(),
            'unique_modules' => $events->pluck('module')->unique()->values()->toArray(),
        ]);
    }

    /**
     * Get dependency/cascade map
     * GET /api/event-mappings/dependencies
     */
    public function getDependencies()
    {
        return response()->json([
            'dependencies' => $this->policyMapper->getDependencyMap(),
        ]);
    }

    /**
     * Get detailed info about which rules apply to an event
     * GET /api/event-mappings/event-rules
     */
    public function getApplicableRules(Request $request)
    {
        $validated = $request->validate([
            'module' => 'required|string',
            'action' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        $event = new PolicyEventDTO(
            module: $validated['module'],
            action: $validated['action'],
            payload: $validated['payload'] ?? [],
        );

        $rules = $this->discoveryEngine->queryRulesForEvent($event);

        return response()->json([
            'event' => [
                'module' => $event->module,
                'action' => $event->action,
            ],
            'applicable_rules' => $rules->map(fn($rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'policy_id' => $rule->policy->id,
                'policy_name' => $rule->policy->name,
                'enforcement_action' => $rule->action_on_breach,
                'conditions_count' => $rule->conditions->count(),
                'conditions' => $rule->conditions->map(fn($c) => [
                    'id' => $c->id,
                    'field' => $c->field,
                    'operator' => $c->operator,
                    'value' => $c->value,
                ])->toArray(),
            ])->toArray(),
            'summary' => [
                'total_rules' => $rules->count(),
                'blocking_rules' => $rules->where('action_on_breach', 'block')->count(),
                'warning_rules' => $rules->where('action_on_breach', 'warn')->count(),
            ],
        ]);
    }
}
