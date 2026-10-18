<?php

namespace App\Modules\Policies\Services\Prediction;

use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Models\ViolationPattern;
use App\Modules\Policies\Models\NearMissIncident;
use App\Modules\Policies\Models\ApprovalOverride;
use App\Core\User\Models\User;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BehaviorTracker
 * 
 * Tracks user and department behavior patterns:
 * - Violation frequency and trends
 * - Near-miss incidents (warnings, escalations)
 * - Exception approvals and overrides
 * - Temporal patterns (time of day, day of week)
 * - Repeat violations (same rule multiple times)
 */
class BehaviorTracker
{
    public const VIOLATION_WINDOW_DAYS = 90;     // Track violations in last 90 days
    public const NEAR_MISS_WINDOW_DAYS = 90;     // Track near-misses in last 90 days
    public const RECENCY_DECAY_DAYS = 30;        // Recent violations weighted more

    /**
     * Record a violation in behavior history
     * 
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    public function recordViolation(PolicyViolation $violation, int $severityScore): void
    {
        // 1. Record violation pattern (for repeat tracking)
        $this->recordViolationPattern($violation, $severityScore);

        // 2. Update user violation metrics
        $this->updateUserMetrics($violation->user_id, $violation, $severityScore);

        // 3. Update department metrics
        if ($violation->user && $violation->user->department_id) {
            $this->updateDepartmentMetrics(
                $violation->user->department_id,
                $violation,
                $severityScore
            );
        }

        // 3. Check for repeated violations (same rule within 30 days)
        $this->checkRepeatedViolation($violation);

        // 4. Analyze temporal patterns
        $this->recordTemporalPattern($violation);
    }

    /**
     * Record near-miss incident (warning/escalation without violation)
     * 
     * @param int $userId
     * @param int $ruleId
     * @param int $policyId
     * @param string $module
     * @param string $action
     * @param string $reason
     */
    public function recordNearMiss(
        int $userId,
        int $ruleId,
        int $policyId,
        string $module,
        string $action,
        string $reason
    ): void {
        NearMissIncident::create([
            'user_id' => $userId,
            'rule_id' => $ruleId,
            'policy_id' => $policyId,
            'module' => $module,
            'action' => $action,
            'reason' => $reason,
            'recorded_at' => now(),
        ]);

        // Update user's near-miss count
        DB::table('policy_violation_patterns')
            ->where('user_id', $userId)
            ->increment('near_miss_count');
    }

    /**
     * Record when an exception was approved (override of rule)
     * 
     * @param int $userId
     * @param int $ruleId
     * @param int $policyId
     * @param int $violations
     * @param int $approvedBy
     * @param string|null $reason
     */
    public function recordApprovalOverride(
        int $userId,
        int $ruleId,
        int $policyId,
        int $violationCount,
        int $approvedBy,
        ?string $reason = null
    ): void {
        ApprovalOverride::create([
            'user_id' => $userId,
            'rule_id' => $ruleId,
            'policy_id' => $policyId,
            'violation_count' => $violationCount,
            'approved_by' => $approvedBy,
            'reason' => $reason,
            'approved_at' => now(),
        ]);

        // Increment override count in patterns
        DB::table('policy_violation_patterns')
            ->where('user_id', $userId)
            ->increment('approval_override_count');
    }

    /**
     * Get user behavior summary
     * 
     * @param int $userId
     * @param int $days
     * @return array
     */
    public function getUserBehaviorSummary(int $userId, int $days = 90): array
    {
        $pattern = ViolationPattern::firstOrCreate(
            ['user_id' => $userId],
            ['violation_count' => 0]
        );

        $recentViolations = PolicyViolation::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        $severeCritical = PolicyViolation::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('severity_score', '>=', 85)
            ->count();

        $repeatedRules = $this->getRepeatedRulesForUser($userId, $days);

        $nearMisses = NearMissIncident::where('user_id', $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->count();

        $overrides = ApprovalOverride::where('user_id', $userId)
            ->where('approved_at', '>=', now()->subDays($days))
            ->count();

        return [
            'user_id' => $userId,
            'total_violations' => $pattern->violation_count,
            'recent_violations' => $recentViolations,
            'critical_violations' => $severeCritical,
            'repeated_violations' => $repeatedRules,
            'near_misses' => $nearMisses,
            'approval_overrides' => $overrides,
            'last_violation' => $pattern->last_violation_at?->diffForHumans(),
            'violation_trend' => $this->calculateTrend($userId, $days),
        ];
    }

    /**
     * Get department behavior summary
     * 
     * @param int $departmentId
     * @param int $days
     * @return array
     */
    public function getDepartmentBehaviorSummary(int $departmentId, int $days = 90): array
    {
        $violations = DB::table('policy_violations')
            ->join('users', 'policy_violations.user_id', '=', 'users.id')
            ->where('users.department_id', $departmentId)
            ->where('policy_violations.created_at', '>=', now()->subDays($days))
            ->count();

        $criticalViolations = DB::table('policy_violations')
            ->join('users', 'policy_violations.user_id', '=', 'users.id')
            ->where('users.department_id', $departmentId)
            ->where('policy_violations.created_at', '>=', now()->subDays($days))
            ->where('policy_violations.severity_score', '>=', 85)
            ->count();

        $users = User::where('department_id', $departmentId)->pluck('id');
        $violatingUsers = PolicyViolation::whereIn('user_id', $users)
            ->where('created_at', '>=', now()->subDays($days))
            ->distinct('user_id')
            ->count('user_id');

        $avgViolationsPerUser = $violatingUsers > 0 ? round($violations / $violatingUsers, 2) : 0;

        return [
            'department_id' => $departmentId,
            'total_violations' => $violations,
            'critical_violations' => $criticalViolations,
            'violating_users' => $violatingUsers,
            'avg_violations_per_user' => $avgViolationsPerUser,
            'total_users' => $users->count(),
            'violation_rate' => $users->count() > 0 
                ? round(($violatingUsers / $users->count()) * 100, 2) . '%'
                : '0%',
        ];
    }

    /**
     * Get frequently repeated violations for user
     * 
     * @param int $userId
     * @param int $days
     * @return array
     */
    private function getRepeatedRulesForUser(int $userId, int $days = 90): array
    {
        return DB::table('policy_violations')
            ->select('rule_id', DB::raw('COUNT(*) as count'))
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('rule_id')
            ->having('count', '>', 1)
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'rule_id')
            ->toArray();
    }

    /**
     * Calculate violation trend (increasing/decreasing/stable)
     * 
     * @param int $userId
     * @param int $days
     * @return string
     */
    private function calculateTrend(int $userId, int $days = 90): string
    {
        $firstHalf = PolicyViolation::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('created_at', '<', now()->subDays($days / 2))
            ->count();

        $secondHalf = PolicyViolation::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days / 2))
            ->count();

        if ($secondHalf > $firstHalf * 1.2) {
            return 'increasing';
        } elseif ($secondHalf < $firstHalf * 0.8) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Record individual violation pattern for rule repeat tracking
     * 
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function recordViolationPattern(PolicyViolation $violation, int $severityScore): void
    {
        DB::table('policy_violation_patterns')->updateOrInsert(
            ['user_id' => $violation->user_id],
            [
                'violation_count' => DB::raw('violation_count + 1'),
                'critical_count' => $severityScore >= 85 ? DB::raw('critical_count + 1') : DB::raw('critical_count'),
                'high_count' => $severityScore >= 70 ? DB::raw('high_count + 1') : DB::raw('high_count'),
                'medium_count' => $severityScore >= 40 ? DB::raw('medium_count + 1') : DB::raw('medium_count'),
                'total_severity_score' => DB::raw("total_severity_score + {$severityScore}"),
                'last_violation_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Update user violation metrics
     * 
     * @param int $userId
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function updateUserMetrics(int $userId, PolicyViolation $violation, int $severityScore): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        // Track violations per rule
        DB::table('policy_rule_violations_by_user')->updateOrInsert(
            [
                'user_id' => $userId,
                'rule_id' => $violation->rule_id,
            ],
            [
                'violation_count' => DB::raw('violation_count + 1'),
                'last_violation_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Track violations per policy
        DB::table('policy_policy_violations_by_user')->updateOrInsert(
            [
                'user_id' => $userId,
                'policy_id' => $violation->policy_id,
            ],
            [
                'violation_count' => DB::raw('violation_count + 1'),
                'last_violation_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Update department violation metrics
     * 
     * @param int $departmentId
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function updateDepartmentMetrics(int $departmentId, PolicyViolation $violation, int $severityScore): void
    {
        DB::table('policy_department_violations')->updateOrInsert(
            [
                'department_id' => $departmentId,
                'rule_id' => $violation->rule_id,
            ],
            [
                'violation_count' => DB::raw('violation_count + 1'),
                'critical_count' => $severityScore >= 85 ? DB::raw('critical_count + 1') : DB::raw('critical_count'),
                'last_violation_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Check if this is a repeat violation (same rule/user within 30 days)
     * 
     * @param PolicyViolation $violation
     */
    private function checkRepeatedViolation(PolicyViolation $violation): void
    {
        $priorViolations = PolicyViolation::where('user_id', $violation->user_id)
            ->where('rule_id', $violation->rule_id)
            ->where('id', '!=', $violation->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        if ($priorViolations > 0) {
            DB::table('policy_repeat_violations')->insertOrIgnore([
                'violation_id' => $violation->id,
                'user_id' => $violation->user_id,
                'rule_id' => $violation->rule_id,
                'prior_count' => $priorViolations,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Analyze temporal patterns (time of violation)
     * 
     * @param PolicyViolation $violation
     */
    private function recordTemporalPattern(PolicyViolation $violation): void
    {
        $hour = $violation->created_at->hour;
        $dayOfWeek = $violation->created_at->dayOfWeek;

        DB::table('policy_temporal_patterns')->updateOrInsert(
            [
                'user_id' => $violation->user_id,
                'hour_of_day' => $hour,
                'day_of_week' => $dayOfWeek,
            ],
            [
                'occurrence_count' => DB::raw('occurrence_count + 1'),
                'updated_at' => now(),
            ]
        );
    }
}
