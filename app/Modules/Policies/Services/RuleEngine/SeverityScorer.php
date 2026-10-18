<?php

namespace App\Modules\Policies\Services\RuleEngine;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\Models\PolicyRule;

/**
 * SeverityScorer
 * 
 * Calculates severity scores for rule violations based on:
 * - Rule severity level (critical, high, medium, low)
 * - Impact of the violation
 * - How many times violated
 * - User/department context
 * 
 * Severity scores help with:
 * - Prioritizing violations for review
 * - Escalation decisions
 * - Enforcement action selection
 * - Reporting & analytics
 */
class SeverityScorer
{
    // Severity level multipliers
    private const SEVERITY_MULTIPLIERS = [
        'critical' => 1.0,
        'high' => 0.75,
        'medium' => 0.5,
        'low' => 0.25,
    ];

    // Base severity thresholds
    private const SEVERITY_THRESHOLDS = [
        'critical' => 85,
        'high' => 70,
        'medium' => 40,
        'low' => 0,
    ];

    /**
     * Calculate severity score for a violation (0-100)
     * 
     * @param EvaluationResultDTO $result
     * @return int Severity score 0-100
     */
    public function calculateSeverityScore(EvaluationResultDTO $result): int
    {
        // Start with rule baseline severity
        $baseScore = $this->getBaseSeverityScore($result->rule->severity);

        // Adjust based on violation context
        $contextMultiplier = $this->calculateContextMultiplier($result);

        // Adjust based on impact
        $impactMultiplier = $this->calculateImpactMultiplier($result);

        // Calculate final score
        $finalScore = (int) ($baseScore * $contextMultiplier * $impactMultiplier);

        // Bound to 0-100
        return min(100, max(0, $finalScore));
    }

    /**
     * Get base severity score from rule severity level
     * 
     * @param string $severityLevel critical, high, medium, low
     * @return int
     */
    private function getBaseSeverityScore(string $severityLevel): int
    {
        return self::SEVERITY_THRESHOLDS[$severityLevel] ?? 50;
    }

    /**
     * Calculate context multiplier based on:
     * - Enforcement action (escalate = higher)
     * - Rule importance (requires_exception_approval = higher)
     * - Policy status
     * 
     * @param EvaluationResultDTO $result
     * @return float 0.5 - 2.0
     */
    private function calculateContextMultiplier(EvaluationResultDTO $result): float
    {
        $multiplier = 1.0;

        // Escalation increases severity
        if ($result->outcome === 'escalate') {
            $multiplier *= 1.5;
        }

        // Blocking actions are more severe
        if ($result->outcome === 'block') {
            $multiplier *= 1.3;
        }

        // Exception-requiring rules are more severe
        if ($result->rule->requires_exception_approval) {
            $multiplier *= 1.2;
        }

        return min(2.0, $multiplier);
    }

    /**
     * Calculate impact multiplier based on:
     * - Days/amount involved (if in payload)
     * - Department scope
     * - Frequency of similar violations
     * 
     * @param EvaluationResultDTO $result
     * @return float 0.5 - 2.0
     */
    private function calculateImpactMultiplier(EvaluationResultDTO $result): float
    {
        $multiplier = 1.0;

        $payload = $result->event->payload;

        // Check for high-impact amounts
        if (isset($payload['amount']) && $payload['amount'] > 10000) {
            $multiplier *= 1.3;
        } elseif (isset($payload['amount']) && $payload['amount'] > 5000) {
            $multiplier *= 1.1;
        }

        // Check for high-impact days (leave, absence)
        if (isset($payload['days_requested']) && $payload['days_requested'] > 20) {
            $multiplier *= 1.2;
        }

        // Check for employee count affected
        if (isset($payload['affected_employees']) && $payload['affected_employees'] > 100) {
            $multiplier *= 1.4;
        }

        return min(2.0, $multiplier);
    }

    /**
     * Categorize severity score into labels
     * 
     * @param int $score 0-100
     * @return string critical|high|medium|low|info
     */
    public function categorizeScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            $score >= 20 => 'low',
            default => 'info',
        };
    }

    /**
     * Get color representation of severity
     * 
     * @param int $score
     * @return string hex color code
     */
    public function getColorForScore(int $score): string
    {
        return match (true) {
            $score >= 85 => '#dc2626', // red
            $score >= 70 => '#ea580c', // orange
            $score >= 40 => '#f59e0b', // amber
            $score >= 20 => '#eab308', // yellow
            default => '#6366f1', // indigo
        };
    }

    /**
     * Get icon representation of severity
     * 
     * @param int $score
     * @return string icon name
     */
    public function getIconForScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'alert-triangle', // critical
            $score >= 70 => 'alert-circle', // high
            $score >= 40 => 'alert-octagon', // medium
            $score >= 20 => 'alert', // low
            default => 'info', // info
        };
    }

    /**
     * Get recommended action based on severity
     * 
     * @param int $score
     * @return string block|escalate|warn|log_only
     */
    public function getRecommendedAction(int $score): string
    {
        return match (true) {
            $score >= 85 => 'block',
            $score >= 70 => 'escalate',
            $score >= 40 => 'warn',
            default => 'log_only',
        };
    }

    /**
     * Get human-readable severity label
     * 
     * @param int $score
     * @return string
     */
    public function getLabel(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Critical Violation',
            $score >= 70 => 'High Risk Violation',
            $score >= 40 => 'Medium Risk Violation',
            $score >= 20 => 'Low Risk Violation',
            default => 'Informational',
        };
    }

    /**
     * Get description of severity
     * 
     * @param int $score
     * @return string
     */
    public function getDescription(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Immediate action required. This violation poses a critical risk.',
            $score >= 70 => 'High priority. This violation should be reviewed and acted upon soon.',
            $score >= 40 => 'Moderate concern. Monitor and take appropriate action.',
            $score >= 20 => 'Minor concern. Log for record keeping.',
            default => 'For information purposes only.',
        };
    }
}
