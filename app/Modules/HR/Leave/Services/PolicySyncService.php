<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyConflict;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Services\ConflictDetectionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Synchronises leave-relevant policy rules from the global Policy module
 * into the Leave module's EventRuleMap, and surfaces conflict summaries
 * for leave-category policies.
 *
 * Sync logic:
 *  1. Find all active Policies tagged module='Leave' or category containing 'leave'.
 *  2. For each PolicyRule in those policies, ensure an EventRuleMap row exists
 *     mapping the rule to the appropriate leave action pattern.
 *  3. Deactivate EventRuleMap rows for rules that are no longer active.
 *  4. Run ConflictDetectionService against leave-category policies.
 */
class PolicySyncService
{
    /** Action patterns to use when a rule lacks an explicit event mapping */
    private const DEFAULT_ACTION_PATTERNS = [
        'annual'    => 'leave_request.submitted',
        'sick'      => 'leave_request.submitted',
        'maternity' => 'leave_request.submitted',
        'paternity' => 'leave_request.submitted',
        'parental'  => 'leave_request.submitted',
        'family'    => 'leave_request.submitted',
        'study'     => 'leave_request.submitted',
        'overtime'  => 'leave_request.submitted',
        'default'   => 'leave_request.*',
    ];

    public function __construct(
        private readonly ConflictDetectionService $conflictService,
    ) {}

    /**
     * Full sync: map rules + detect conflicts.
     * Returns summary array.
     */
    public function sync(): array
    {
        $policies = $this->resolveLeaveRelatedPolicies();

        if ($policies->isEmpty()) {
            Log::info('PolicySyncService: No leave-related policies found.');
            return ['mapped' => 0, 'deactivated' => 0, 'conflicts' => []];
        }

        $mapped      = 0;
        $deactivated = 0;
        $conflicts   = [];

        DB::transaction(function () use ($policies, &$mapped, &$deactivated, &$conflicts) {
            foreach ($policies as $policy) {
                [$m, $d] = $this->syncPolicyRules($policy);
                $mapped      += $m;
                $deactivated += $d;

                // Detect conflicts for this policy against other leave policies
                $detected = $this->conflictService->detect($policy, Auth::id());
                foreach ($detected as $conflict) {
                    $conflicts[] = [
                        'id'          => $conflict->id,
                        'policy_a'    => $conflict->policyA?->name,
                        'policy_b'    => $conflict->policyB?->name,
                        'type'        => $conflict->conflict_type,
                        'description' => $conflict->description,
                    ];
                }
            }
        });

        Log::info("PolicySyncService: mapped={$mapped}, deactivated={$deactivated}, conflicts=" . count($conflicts));

        return [
            'mapped'      => $mapped,
            'deactivated' => $deactivated,
            'conflicts'   => $conflicts,
        ];
    }

    /**
     * Sync a single policy's rules into EventRuleMap.
     * Returns [mapped_count, deactivated_count].
     */
    public function syncPolicyRules(Policy $policy): array
    {
        $mapped      = 0;
        $deactivated = 0;

        $activeRuleIds = $policy->rules()->where('active', true)->pluck('id');

        foreach ($activeRuleIds as $ruleId) {
            $rule    = PolicyRule::with(['conditions', 'eventMaps'])->find($ruleId);
            $pattern = $this->resolveActionPattern($rule, $policy);

            $existing = EventRuleMap::where('rule_id', $ruleId)
                ->where('module', 'Leave')
                ->where('action_pattern', $pattern)
                ->first();

            if (! $existing) {
                EventRuleMap::create([
                    'rule_id'        => $ruleId,
                    'module'         => 'Leave',
                    'action_pattern' => $pattern,
                    'payload_filter' => $this->buildPayloadFilter($rule, $policy),
                    'active'         => true,
                ]);
                $mapped++;
            } elseif (! $existing->active) {
                $existing->update(['active' => true]);
                $mapped++;
            }
        }

        // Deactivate maps for rules that are no longer active
        $deactivated += EventRuleMap::where('module', 'Leave')
            ->whereHas('rule', fn($q) => $q->where('policy_id', $policy->id)->where('active', false))
            ->where('active', true)
            ->update(['active' => false]);

        return [$mapped, $deactivated];
    }

    /**
     * Return open conflicts involving leave-category policies.
     */
    public function openConflicts(): Collection
    {
        $policyIds = $this->resolveLeaveRelatedPolicies()->pluck('id');

        return PolicyConflict::with(['policyA', 'policyB', 'ruleA', 'ruleB'])
            ->where(
                fn($q) => $q->whereIn('policy_a_id', $policyIds)
                             ->orWhereIn('policy_b_id', $policyIds)
            )
            ->whereIn('status', ['open', 'acknowledged'])
            ->latest()
            ->get();
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function resolveLeaveRelatedPolicies(): Collection
    {
        return Policy::with(['rules.conditions', 'clauses'])
            ->where('status', 'active')
            ->whereHas('category', fn($c) => $c->whereRaw("LOWER(name) LIKE '%leave%'"))
            ->get();
    }

    private function resolveActionPattern(PolicyRule $rule, Policy $policy): string
    {
        // If the rule already has an explicit Leave event map, reuse its pattern
        $existing = $rule->eventMaps->where('module', 'Leave')->first();
        if ($existing) {
            return $existing->action_pattern;
        }

        // Derive from policy name / description keywords
        $text = strtolower($policy->name . ' ' . ($policy->description ?? ''));

        foreach (self::DEFAULT_ACTION_PATTERNS as $keyword => $pattern) {
            if ($keyword !== 'default' && str_contains($text, $keyword)) {
                return $pattern;
            }
        }

        return self::DEFAULT_ACTION_PATTERNS['default'];
    }

    private function buildPayloadFilter(PolicyRule $rule, Policy $policy): ?array
    {
        $filter = [];

        // If the policy is scoped to specific leave types by name, add a filter
        $leaveTypes = LeaveType::where('status', 1)->get();
        foreach ($leaveTypes as $lt) {
            if (str_contains(strtolower($policy->name), strtolower($lt->name))) {
                $filter['statutory_type'] = $lt->statutory_type;
                break;
            }
        }

        return $filter ?: null;
    }
}
