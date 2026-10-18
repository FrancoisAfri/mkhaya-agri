<?php

namespace App\Modules\Policies\Services;

use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use Illuminate\Support\Facades\DB;

/**
 * Validates module actions against applicable policies
 * 
 * This service:
 * - Finds policies that apply to a module
 * - Checks if rules exist for the action
 * - Evaluates rules and returns violations
 * - Supports enforcement actions (block, warn, escalate, log_only)
 */
class ModuleActionValidator
{
    public function __construct(
        private readonly RuleEvaluationEngine $evaluationEngine,
    ) {}

    /**
     * Get all active policies for a module
     * 
     * @param string $module Module name (e.g., 'Leave', 'Claims', 'Fleet')
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPoliciesForModule(string $module)
    {
        return Policy::where('status', 'active')
            ->where('module', $module)
            ->with(['clauses', 'rules', 'documents'])
            ->get();
    }

    /**
     * Get rules that apply to a specific module action
     * 
     * @param string $module Module name
     * @param string $action Action name (e.g., 'leave_request.submitted')
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRulesForAction(string $module, string $action)
    {
        return EventRuleMap::where('event_module', $module)
            ->where('event_action', $action)
            ->with(['rule'])
            ->get()
            ->pluck('rule')
            ->filter(fn($rule) => $rule && $rule->policy->status === 'active');
    }

    /**
     * Check if an action violates any policies
     * Returns evaluation results with violations
     * 
     * @param PolicyEventDTO $event
     * @return array Array of evaluation results
     */
    public function validateAction(PolicyEventDTO $event): array
    {
        $policies = $this->getPoliciesForModule($event->module);

        if ($policies->isEmpty()) {
            // No policies for this module
            return [
                'valid' => true,
                'violations' => [],
                'blocking_violations' => [],
            ];
        }

        // Evaluate any matching rules
        $results = $this->evaluationEngine->evaluate($event);

        return [
            'valid' => $results->every(fn($r) => $r->passed),
            'violations' => $results->filter(fn($r) => !$r->passed)->values(),
            'blocking_violations' => $results->filter(
                fn($r) => !$r->passed && $r->rule->enforcement_action === 'block'
            )->values(),
        ];
    }

    /**
     * Check if a specific action is allowed (returns boolean)
     * Blocks action if any blocking rules are violated
     * 
     * @param PolicyEventDTO $event
     * @return bool
     */
    public function isActionAllowed(PolicyEventDTO $event): bool
    {
        $validation = $this->validateAction($event);
        return empty($validation['blocking_violations']);
    }

    /**
     * Get detailed violation info for action
     * Useful for error messages shown to users
     * 
     * @param PolicyEventDTO $event
     * @return array
     */
    public function getViolationDetails(PolicyEventDTO $event): array
    {
        $validation = $this->validateAction($event);
        
        return [
            'violations' => $validation['violations']
                ->map(function ($result) {
                    return [
                        'rule_id' => $result->rule->id,
                        'policy_id' => $result->rule->policy_id,
                        'policy_name' => $result->rule->policy->name,
                        'rule_name' => $result->rule->name,
                        'description' => $result->rule->description,
                        'enforcement_action' => $result->rule->enforcement_action,
                        'reason' => $result->reason,
                    ];
                })
                ->toArray(),
            'is_blocked' => !empty($validation['blocking_violations']),
            'block_reason' => !empty($validation['blocking_violations'])
                ? 'This action violates policy rules: ' . 
                  $validation['blocking_violations']
                    ->map(fn($r) => $r->rule->policy->name . ' - ' . $r->rule->name)
                    ->join('; ')
                : null,
        ];
    }

    /**
     * Get applicable policies for a user in a department
     * Useful for showing users which policies affect them
     * 
     * @param string $module
     * @param int $departmentId
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApplicablePolicies(string $module, int $departmentId, int $userId)
    {
        return Policy::where('status', 'active')
            ->where('module', $module)
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department_id')
                    ->orWhere('department_id', $departmentId);
            })
            ->with([
                'clauses' => fn($q) => $q->where('status', 'approved'),
                'rules' => fn($q) => $q->where('status', 'active'),
            ])
            ->get();
    }
}
