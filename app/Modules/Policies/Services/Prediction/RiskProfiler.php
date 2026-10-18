<?php

namespace App\Modules\Policies\Services\Prediction;

use App\Modules\Policies\Models\RiskProfile;
use App\Modules\Policies\Models\RiskProfileHistory;
use App\Modules\Policies\Models\PolicyViolation;
use App\Core\User\Models\User;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RiskProfiler
 * 
 * Calculates risk scores (0-100) for users and departments based on:
 * - Violation frequency and severity
 * - Recency (recent violations weighted heavier)
 * - Repeat violations (same rule multiple times)
 * - Near-miss incidents
 * - Exception approval overrides
 * - Compliance improvement (decreasing trend)
 */
class RiskProfiler
{
    public const RISK_THRESHOLD_CRITICAL = 80;
    public const RISK_THRESHOLD_HIGH = 60;
    public const RISK_THRESHOLD_MEDIUM = 40;
    public const RISK_THRESHOLD_LOW = 20;

    // Risk score multipliers
    private const VIOLATION_MULTIPLIER = 5;           // Each violation adds up to 5 points
    private const SEVERITY_MULTIPLIER = 0.3;          // Each severity point adds 0.3 points
    private const REPEAT_VIOLATION_MULTIPLIER = 2;    // Repeat violations add 2x per instance
    private const NEAR_MISS_MULTIPLIER = 1.5;         // Each near-miss adds 1.5 points
    private const APPROVAL_OVERRIDE_MULTIPLIER = 3;   // Each override adds 3 points
    private const RECENCY_BOOST = 1.5;                // Recent violations (< 30 days) weighted 1.5x

    /**
     * Calculate risk profile for a user
     * 
     * @param int $userId
     * @param int $days
     * @return array
     */
    public function calculateUserRiskProfile(int $userId, int $days = 90): array
    {
        $score = 0;
        $breakdown = [];

        // 1. Violation frequency score
        $recentViolations = PolicyViolation::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        $violationScore = min(100, $recentViolations->count() * self::VIOLATION_MULTIPLIER);
        $score += $violationScore * 0.3; // 30% weight
        $breakdown['violation_frequency'] = round($violationScore, 2);

        // 2. Severity score
        $totalSeverity = $recentViolations->sum('severity_score');
        $averageSeverity = $recentViolations->isNotEmpty() ?
            $totalSeverity / $recentViolations->count() : 0;
        
        $severityScore = min(100, $averageSeverity * self::SEVERITY_MULTIPLIER);
        $score += $severityScore * 0.25; // 25% weight
        $breakdown['severity_level'] = round($severityScore, 2);

        // 3. Recency score (recent violations boost score)
        $recentViolations30 = $recentViolations->filter(function ($v) {
            return $v->created_at->gte(now()->subDays(30));
        })->count();

        $recencyScore = min(100, ($recentViolations30 / max(1, $recentViolations->count()) * 100) * self::RECENCY_BOOST);
        $score += $recencyScore * 0.2; // 20% weight
        $breakdown['recency'] = round($recencyScore, 2);

        // 4. Repeat violations score
        $repeatViolations = $this->countRepeatViolations($userId, $days);
        $repeatScore = min(100, $repeatViolations * self::REPEAT_VIOLATION_MULTIPLIER);
        $score += $repeatScore * 0.15; // 15% weight
        $breakdown['repeat_violations'] = round($repeatScore, 2);

        // 5. Near-miss incidents score
        $nearMisses = DB::table('policy_near_misses')
            ->where('user_id', $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->count();

        $nearMissScore = min(100, $nearMisses * self::NEAR_MISS_MULTIPLIER);
        $score += $nearMissScore * 0.05; // 5% weight
        $breakdown['near_misses'] = round($nearMissScore, 2);

        // 6. Approval override score (exceptions used to bypass rules)
        $overrides = DB::table('policy_approval_overrides')
            ->where('user_id', $userId)
            ->where('approved_at', '>=', now()->subDays($days))
            ->count();

        $overrideScore = min(100, $overrides * self::APPROVAL_OVERRIDE_MULTIPLIER);
        // No weight - just informational
        $breakdown['approval_overrides'] = round($overrideScore, 2);

        // Final score (cap at 100, floor at 0)
        $finalScore = min(100, max(0, $score));

        return [
            'user_id' => $userId,
            'risk_score' => round($finalScore, 2),
            'risk_category' => $this->categorizeRiskScore($finalScore),
            'risk_level' => $this->getRiskLevel($finalScore),
            'breakdown' => $breakdown,
            'calculated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Calculate risk profile for a department
     * 
     * @param int $departmentId
     * @param int $days
     * @return array
     */
    public function calculateDepartmentRiskProfile(int $departmentId, int $days = 90): array
    {
        $users = User::where('department_id', $departmentId)->pluck('id');
        
        if ($users->isEmpty()) {
            return [
                'department_id' => $departmentId,
                'risk_score' => 0,
                'risk_category' => 'low',
                'user_count' => 0,
                'violating_users' => 0,
                'high_risk_users' => 0,
            ];
        }

        // Calculate average user risk scores
        $userRisks = [];
        $highRiskCount = 0;

        foreach ($users as $userId) {
            $profile = $this->calculateUserRiskProfile($userId, $days);
            $userRisks[] = $profile['risk_score'];

            if ($profile['risk_score'] >= self::RISK_THRESHOLD_HIGH) {
                $highRiskCount++;
            }
        }

        $averageRisk = !empty($userRisks) ? array_sum($userRisks) / count($userRisks) : 0;

        // Department risk score is average + high-risk user concentration
        $highRiskPercentage = ($highRiskCount / count($userRisks)) * 100;
        $departmentScore = ($averageRisk * 0.7) + ($highRiskPercentage * 0.3);
        $departmentScore = min(100, max(0, $departmentScore));

        // Violations in department (recent)
        $deptViolations = PolicyViolation::join('users', 'policy_violations.user_id', '=', 'users.id')
            ->where('users.department_id', $departmentId)
            ->where('policy_violations.created_at', '>=', now()->subDays($days))
            ->count();

        $violatingUsers = PolicyViolation::join('users', 'policy_violations.user_id', '=', 'users.id')
            ->where('users.department_id', $departmentId)
            ->where('policy_violations.created_at', '>=', now()->subDays($days))
            ->distinct('policy_violations.user_id')
            ->count('policy_violations.user_id');

        return [
            'department_id' => $departmentId,
            'risk_score' => round($departmentScore, 2),
            'risk_category' => $this->categorizeRiskScore($departmentScore),
            'risk_level' => $this->getRiskLevel($departmentScore),
            'user_count' => $users->count(),
            'high_risk_users' => $highRiskCount,
            'high_risk_percentage' => round($highRiskPercentage, 2),
            'violating_users' => $violatingUsers,
            'total_violations' => $deptViolations,
            'average_user_risk' => round($averageRisk, 2),
        ];
    }

    /**
     * Store user risk profile in database
     * 
     * @param int $userId
     * @param int $days
     */
    public function storeUserRiskProfile(int $userId, int $days = 90): void
    {
        $profile = $this->calculateUserRiskProfile($userId, $days);

        // First record historical entry
        RiskProfileHistory::create([
            'user_id' => $userId,
            'risk_score' => $profile['risk_score'],
            'risk_category' => $profile['risk_category'],
            'recorded_at' => now(),
        ]);

        // Then update current profile
        RiskProfile::updateOrCreate(
            ['user_id' => $userId],
            [
                'risk_score' => $profile['risk_score'],
                'risk_category' => $profile['risk_category'],
                'breakdown' => json_encode($profile['breakdown']),
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * Store department risk profile in database
     * 
     * @param int $departmentId
     * @param int $days
     */
    public function storeDepartmentRiskProfile(int $departmentId, int $days = 90): void
    {
        $profile = $this->calculateDepartmentRiskProfile($departmentId, $days);

        // Record historical entry
        RiskProfileHistory::create([
            'department_id' => $departmentId,
            'risk_score' => $profile['risk_score'],
            'risk_category' => $profile['risk_category'],
            'recorded_at' => now(),
        ]);

        // Update current profile
        RiskProfile::updateOrCreate(
            ['department_id' => $departmentId],
            [
                'risk_score' => $profile['risk_score'],
                'risk_category' => $profile['risk_category'],
                'breakdown' => json_encode($profile),
                'calculated_at' => now(),
            ]
        );
    }

    /**
     * Get high-risk users (score >= 60)
     * 
     * @param int $threshold
     * @param int $limit
     * @return array
     */
    public function getHighRiskUsers(int $threshold = self::RISK_THRESHOLD_HIGH, int $limit = 50): array
    {
        return RiskProfile::where('user_id', '!=', null)
            ->where('risk_score', '>=', $threshold)
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->with('user')
            ->get()
            ->map(function ($profile) {
                return [
                    'user_id' => $profile->user_id,
                    'user_name' => $profile->user?->name,
                    'department' => $profile->user?->department?->name,
                    'risk_score' => $profile->risk_score,
                    'risk_category' => $profile->risk_category,
                    'calculated_at' => $profile->calculated_at?->diffForHumans(),
                ];
            })
            ->toArray();
    }

    /**
     * Get high-risk departments (score >= 60)
     * 
     * @param int $threshold
     * @param int $limit
     * @return array
     */
    public function getHighRiskDepartments(int $threshold = self::RISK_THRESHOLD_HIGH, int $limit = 20): array
    {
        return RiskProfile::where('department_id', '!=', null)
            ->where('risk_score', '>=', $threshold)
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->with('department')
            ->get()
            ->map(function ($profile) {
                return [
                    'department_id' => $profile->department_id,
                    'department_name' => $profile->department?->name,
                    'risk_score' => $profile->risk_score,
                    'risk_category' => $profile->risk_category,
                    'breakdown' => json_decode($profile->breakdown, true),
                    'calculated_at' => $profile->calculated_at?->diffForHumans(),
                ];
            })
            ->toArray();
    }

    /**
     * Get risk score trend for user (last 30 days)
     * 
     * @param int $userId
     * @return array
     */
    public function getUserRiskTrend(int $userId): array
    {
        $history = RiskProfileHistory::where('user_id', $userId)
            ->where('recorded_at', '>=', now()->subDays(30))
            ->orderBy('recorded_at')
            ->get()
            ->map(function ($record) {
                return [
                    'date' => $record->recorded_at->format('Y-m-d'),
                    'score' => $record->risk_score,
                    'category' => $record->risk_category,
                ];
            })
            ->toArray();

        return [
            'user_id' => $userId,
            'trend' => $history,
            'current_score' => $history ? end($history)['score'] : 0,
            'trend_direction' => $this->calculateTrendDirection($history),
        ];
    }

    /**
     * Count repeat violations (same rule per user)
     * 
     * @param int $userId
     * @param int $days
     * @return int
     */
    private function countRepeatViolations(int $userId, int $days = 90): int
    {
        return DB::table('policy_violations')
            ->select('rule_id', DB::raw('COUNT(*) as count'))
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('rule_id')
            ->having('count', '>', 1)
            ->count();
    }

    /**
     * Categorize risk score into category
     * 
     * @param float $score
     * @return string
     */
    private function categorizeRiskScore(float $score): string
    {
        return match (true) {
            $score >= self::RISK_THRESHOLD_CRITICAL => 'critical',
            $score >= self::RISK_THRESHOLD_HIGH => 'high',
            $score >= self::RISK_THRESHOLD_MEDIUM => 'medium',
            $score >= self::RISK_THRESHOLD_LOW => 'low',
            default => 'minimal',
        };
    }

    /**
     * Get user-friendly risk level
     * 
     * @param float $score
     * @return array
     */
    private function getRiskLevel(float $score): array
    {
        $level = $this->categorizeRiskScore($score);

        return [
            'category' => $level,
            'color' => match ($level) {
                'critical' => '#DC2626',
                'high' => '#EA580C',
                'medium' => '#F59E0B',
                'low' => '#10B981',
                default => '#6B7280',
            },
            'description' => match ($level) {
                'critical' => 'Critical Risk: Requires immediate action',
                'high' => 'High Risk: Close monitoring recommended',
                'medium' => 'Medium Risk: Watch for trends',
                'low' => 'Low Risk: Standard monitoring',
                default => 'Minimal Risk: No action needed',
            },
        ];
    }

    /**
     * Calculate trend direction from history
     * 
     * @param array $history
     * @return string
     */
    private function calculateTrendDirection(array $history): string
    {
        if (count($history) < 2) {
            return 'insufficient_data';
        }

        $firstScore = $history[0]['score'];
        $lastScore = $history[count($history) - 1]['score'];
        $diff = $lastScore - $firstScore;

        if ($diff > 5) {
            return 'increasing';
        } elseif ($diff < -5) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }
}
