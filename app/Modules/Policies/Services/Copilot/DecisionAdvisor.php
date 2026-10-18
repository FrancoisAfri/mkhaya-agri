<?php

namespace App\Modules\Policies\Services\Copilot;

use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Services\Predictions\RiskProfiler;
use App\Core\User\Models\User;
use Carbon\Carbon;

/**
 * DecisionAdvisor
 * 
 * Provide intelligent recommendations about policy compliance:
 * - Predict decision outcomes
 * - Explain rejection reasons
 * - Suggest alternatives
 * - Highlight risk factors
 */
class DecisionAdvisor
{
    private RiskProfiler $riskProfiler;

    public function __construct(RiskProfiler $riskProfiler)
    {
        $this->riskProfiler = $riskProfiler;
    }

    /**
     * Advise on proposed action
     * 
     * @param int $userId
     * @param int $policyId
     * @param array $actionData
     * @return array
     */
    public function adviseOnAction(int $userId, int $policyId, array $actionData): array
    {
        $user = User::findOrFail($userId);
        $policy = Policy::findOrFail($policyId);

        $riskProfile = $this->riskProfiler->getProfile($userId);
        $violations = $this->getRecentViolations($userId, $policyId, days: 90);

        $approval_probability = $this->calculateApprovalProbability(
            $user,
            $policy,
            $violations,
            $riskProfile,
            $actionData
        );

        return [
            'approval_probability' => $approval_probability,
            'recommendation' => $this->generateRecommendation($approval_probability, $riskProfile),
            'risk_factors' => $this->identifyRiskFactors($user, $policy, $violations, $riskProfile),
            'approval_conditions' => $this->getApprovalConditions($policy, $user),
            'alternatives' => $this->suggestAlternatives($user, $policy, $actionData),
            'historical_context' => [
                'recent_violations' => $violations->count(),
                'violation_trend' => $this->getViolationTrend($userId, $policyId),
            ],
        ];
    }

    /**
     * Get top reasons for rejection of similar requests
     * 
     * @param int $policyId
     * @param int $limit
     * @return array
     */
    public function getRejectionReasons(int $policyId, int $limit = 5): array
    {
        $violations = PolicyViolation::where('policy_id', $policyId)
            ->where('status', 'rejected')
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw('reason, count(*) as count')
            ->groupBy('reason')
            ->orderByDesc('count')
            ->limit($limit)
            ->pluck('count', 'reason')
            ->toArray();

        return $violations;
    }

    /**
     * Predict outcome of policy submission
     * 
     * @param int $userId
     * @param int $policyId
     * @param array $submissionData
     * @return array
     */
    public function predictOutcome(int $userId, int $policyId, array $submissionData): array
    {
        $user = User::findOrFail($userId);
        $policy = Policy::findOrFail($policyId);

        $factors = $this->analyzeSubmissionFactors($user, $policy, $submissionData);

        return [
            'predicted_outcome' => $this->predictDecision($factors),
            'confidence' => $factors['confidence'],
            'factors' => $factors['analysis'],
            'expected_timeline' => $this->estimateApprovalTime($policy, $user),
            'required_documentation' => $this->getRequiredDocumentation($policy, $user),
        ];
    }

    /**
     * Get recommendations for user to comply
     * 
     * @param int $userId
     * @param int $policyId
     * @return array
     */
    public function getComplianceRecommendations(int $userId, int $policyId): array
    {
        $user = User::findOrFail($userId);
        $policy = Policy::findOrFail($policyId);
        $violations = $this->getRecentViolations($userId, $policyId, days: 180);

        $recommendations = [];

        if ($violations->count() > 3) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'compliance_training',
                'message' => "You have {$violations->count()} recent violations. Consider compliance training for {$policy->name}",
                'action' => 'enroll_training',
                'policy_id' => $policyId,
            ];
        }

        if ($this->hasFailedRequests($userId, $policyId)) {
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'documentation',
                'message' => 'Your recent requests were rejected. Review required documentation.',
                'action' => 'review_documentation',
                'details' => $this->getCommonDocumentationIssues($userId, $policyId),
            ];
        }

        $riskProfile = $this->riskProfiler->getProfile($userId);
        if ($riskProfile['risk_score'] > 70) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'risk_mitigation',
                'message' => 'Your compliance risk is elevated. Schedule review with manager.',
                'action' => 'schedule_review',
            ];
        }

        // Add policy-specific recommendations
        $policyRecommendations = $this->getPolicySpecificRecommendations($policy, $user, $violations);
        $recommendations = array_merge($recommendations, $policyRecommendations);

        return array_filter($recommendations);
    }

    /**
     * Calculate approval probability
     * 
     * @param User $user
     * @param Policy $policy
     * @param mixed $violations
     * @param array $riskProfile
     * @param array $actionData
     * @return float
     */
    private function calculateApprovalProbability(
        User $user,
        Policy $policy,
        $violations,
        array $riskProfile,
        array $actionData
    ): float {
        $score = 0.5; // Base score

        // Factor 1: User compliance history (30%)
        $compliance_score = max(0, 1 - ($violations->count() / 10)); // Fewer violations = higher score
        $score += $compliance_score * 0.3;

        // Factor 2: User risk profile (25%)
        $risk_factor = 1 - ($riskProfile['risk_score'] / 100);
        $score += $risk_factor * 0.25;

        // Factor 3: Policy strictness (20%)
        $policy_leniency = $policy->approval_strictness ?? 0.5;
        $score += (1 - $policy_leniency) * 0.2;

        // Factor 4: Data quality (15%)
        $data_quality = $this->assessDataQuality($actionData);
        $score += $data_quality * 0.15;

        // Factor 5: Timing/Context (10%)
        $timing_score = $this->assessTiming($actionData);
        $score += $timing_score * 0.1;

        return min(max($score, 0), 1.0); // Clamp to 0-1
    }

    /**
     * Generate recommendation based on approval probability
     * 
     * @param float $probability
     * @param array $riskProfile
     * @return string
     */
    private function generateRecommendation(float $probability, array $riskProfile): string
    {
        if ($probability > 0.8) {
            return "This action is likely to be approved. Proceed with confidence.";
        }

        if ($probability > 0.6) {
            return "This action may be approved, but has some risk. Ensure all documentation is complete.";
        }

        if ($probability > 0.4) {
            return "This action has moderate rejection risk. Consider alternatives or waiting.";
        }

        return "This action is likely to be rejected based on policy and your history. Consider exception request.";
    }

    /**
     * Identify risk factors
     * 
     * @param User $user
     * @param Policy $policy
     * @param mixed $violations
     * @param array $riskProfile
     * @return array
     */
    private function identifyRiskFactors(
        User $user,
        Policy $policy,
        $violations,
        array $riskProfile
    ): array {
        $factors = [];

        if ($violations->count() > 0) {
            $factors[] = [
                'factor' => 'recent_violations',
                'severity' => $violations->count() > 3 ? 'high' : 'medium',
                'details' => "{$violations->count()} violations in last 90 days",
            ];
        }

        if ($riskProfile['risk_score'] > 70) {
            $factors[] = [
                'factor' => 'high_risk_user',
                'severity' => 'high',
                'details' => "Risk score: {$riskProfile['risk_score']}/100",
            ];
        }

        if ($policy->approval_strictness > 0.7) {
            $factors[] = [
                'factor' => 'strict_policy',
                'severity' => 'medium',
                'details' => 'This policy requires high standards of compliance',
            ];
        }

        if ($this->hasFailedRequests($user->id, $policy->id)) {
            $factors[] = [
                'factor' => 'previous_rejections',
                'severity' => 'medium',
                'details' => 'Similar requests have been rejected before',
            ];
        }

        return $factors;
    }

    /**
     * Get approval conditions for policy
     * 
     * @param Policy $policy
     * @param User $user
     * @return array
     */
    private function getApprovalConditions(Policy $policy, User $user): array
    {
        $conditions = [];

        // Get all policy rules and their conditions
        $rules = $policy->rules()->get();

        foreach ($rules as $rule) {
            $conditions[] = [
                'rule' => $rule->name,
                'condition' => $rule->condition,
                'consequence' => $rule->consequence,
            ];
        }

        return $conditions;
    }

    /**
     * Suggest alternatives to proposed action
     * 
     * @param User $user
     * @param Policy $policy
     * @param array $actionData
     * @return array
     */
    private function suggestAlternatives(User $user, Policy $policy, array $actionData): array
    {
        $alternatives = [];

        // If requesting leave, suggest different dates
        if ($actionData['type'] === 'leave_request' ?? false) {
            $alternatives[] = [
                'alternative' => 'alternative_date',
                'suggestion' => 'Try submitting on a different date when policy constraints are less restrictive',
                'reason' => 'Timing may impact approval probability',
            ];
        }

        // Suggest exception request
        $alternatives[] = [
            'alternative' => 'exception_request',
            'suggestion' => 'Request an exception if business case is valid',
            'reason' => 'Business exceptions can bypass strict policy requirements',
        ];

        // Suggest waiting period
        $alternatives[] = [
            'alternative' => 'wait_period',
            'suggestion' => 'Wait 30 days before resubmitting',
            'reason' => 'Recent violations impact current approval probability',
        ];

        return $alternatives;
    }

    /**
     * Get recent violations
     * 
     * @param int $userId
     * @param int $policyId
     * @param int $days
     * @return mixed
     */
    private function getRecentViolations(int $userId, int $policyId, int $days = 90)
    {
        return PolicyViolation::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->get();
    }

    /**
     * Get violation trend (increasing/decreasing)
     * 
     * @param int $userId
     * @param int $policyId
     * @return string
     */
    private function getViolationTrend(int $userId, int $policyId): string
    {
        $current_month = PolicyViolation::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        $previous_month = PolicyViolation::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->where('created_at', '>=', Carbon::now()->subMonth()->startOfMonth())
            ->where('created_at', '<', Carbon::now()->startOfMonth())
            ->count();

        if ($current_month > $previous_month) {
            return 'increasing';
        }

        if ($current_month < $previous_month) {
            return 'decreasing';
        }

        return 'stable';
    }

    /**
     * Assess data quality
     * 
     * @param array $actionData
     * @return float
     */
    private function assessDataQuality(array $actionData): float
    {
        $score = 0.5;

        if (isset($actionData['reason']) && strlen($actionData['reason']) > 10) {
            $score += 0.2;
        }

        if (isset($actionData['supporting_documents']) && !empty($actionData['supporting_documents'])) {
            $score += 0.2;
        }

        if (isset($actionData['manager_note'])) {
            $score += 0.1;
        }

        return min($score, 1.0);
    }

    /**
     * Assess timing of action
     * 
     * @param array $actionData
     * @return float
     */
    private function assessTiming(array $actionData): float
    {
        if (!isset($actionData['start_date'])) {
            return 0.5;
        }

        $daysInAdvance = Carbon::parse($actionData['start_date'])->diffInDays(Carbon::today());

        // Earlier submission = better
        if ($daysInAdvance >= 30) {
            return 1.0;
        }

        if ($daysInAdvance >= 14) {
            return 0.8;
        }

        if ($daysInAdvance >= 7) {
            return 0.6;
        }

        return 0.3;
    }

    /**
     * Predict decision (approved/rejected/pending)
     * 
     * @param array $factors
     * @return string
     */
    private function predictDecision(array $factors): string
    {
        $confidence = $factors['confidence'];

        if ($confidence > 0.8) {
            return 'approved';
        }

        if ($confidence < 0.4) {
            return 'rejected';
        }

        return 'pending';
    }

    /**
     * Estimate approval time
     * 
     * @param Policy $policy
     * @param User $user
     * @return string
     */
    private function estimateApprovalTime(Policy $policy, User $user): string
    {
        // Base time on policy + escalations
        $baseTime = $policy->average_approval_days ?? 5;

        if ($user->is_high_risk ?? false) {
            $baseTime += 3;
        }

        return "{$baseTime} business days";
    }

    /**
     * Analyze submission factors for outcome prediction
     *
     * @param User $user
     * @param Policy $policy
     * @param array $submissionData
     * @return array{confidence: float, analysis: array}
     */
    private function analyzeSubmissionFactors(User $user, Policy $policy, array $submissionData): array
    {
        $riskProfile    = $this->riskProfiler->getProfile($user->id);
        $violations     = $this->getRecentViolations($user->id, $policy->id, days: 90);

        $dataQuality    = $this->assessDataQuality($submissionData);
        $timingScore    = $this->assessTiming($submissionData);
        $complianceBase = max(0.0, 1 - ($violations->count() / 10));
        $riskFactor     = 1 - ($riskProfile['risk_score'] / 100);

        $confidence = round(
            ($complianceBase * 0.35) + ($riskFactor * 0.30) + ($dataQuality * 0.20) + ($timingScore * 0.15),
            2
        );

        return [
            'confidence' => $confidence,
            'analysis'   => [
                ['factor' => 'compliance_history', 'score' => round($complianceBase, 2), 'weight' => 0.35],
                ['factor' => 'risk_profile',        'score' => round($riskFactor, 2),    'weight' => 0.30],
                ['factor' => 'data_quality',        'score' => round($dataQuality, 2),   'weight' => 0.20],
                ['factor' => 'submission_timing',   'score' => round($timingScore, 2),   'weight' => 0.15],
            ],
        ];
    }

    /**
     * Get required documentation
     *
     * @param Policy $policy
     * @param User $user
     * @return array
     */
    private function getRequiredDocumentation(Policy $policy, User $user): array
    {
        $docs = [];

        // Documents explicitly attached to this policy
        $attached = $policy->documents()->get(['id', 'filename', 'description']);
        foreach ($attached as $doc) {
            $docs[] = [
                'document'    => $doc->filename,
                'description' => $doc->description ?? 'Policy document',
                'required'    => true,
                'source'      => 'policy_attachment',
            ];
        }

        // Attestation requirement
        if ($policy->requires_attestation) {
            $docs[] = [
                'document'    => 'Signed attestation form',
                'description' => "Confirmation that you have read and understood {$policy->name}",
                'required'    => true,
                'source'      => 'attestation',
            ];
        }

        // Rule-level conditions as documentation hints
        $rules = $policy->rules()->with('conditions')->get();
        foreach ($rules as $rule) {
            foreach ($rule->conditions as $condition) {
                $conditionText = is_array($condition->condition)
                    ? implode(', ', $condition->condition)
                    : (string) ($condition->condition ?? '');

                if (stripos($conditionText, 'document') !== false || stripos($conditionText, 'proof') !== false) {
                    $docs[] = [
                        'document'    => "Supporting proof for: {$rule->name}",
                        'description' => $conditionText,
                        'required'    => false,
                        'source'      => 'rule_condition',
                    ];
                }
            }
        }

        // Fallback when no specifics are configured
        if (empty($docs)) {
            $docs[] = [
                'document'    => 'Supporting documentation',
                'description' => 'Provide any documentation that supports your request',
                'required'    => false,
                'source'      => 'generic',
            ];
        }

        return $docs;
    }

    /**
     * Check if user has failed requests
     * 
     * @param int $userId
     * @param int $policyId
     * @return bool
     */
    private function hasFailedRequests(int $userId, int $policyId): bool
    {
        return PolicyViolation::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->where('status', 'rejected')
            ->where('created_at', '>=', Carbon::now()->subMonths(3))
            ->exists();
    }

    /**
     * Get common documentation issues
     * 
     * @param int $userId
     * @param int $policyId
     * @return array
     */
    private function getCommonDocumentationIssues(int $userId, int $policyId): array
    {
        $rejectedExplanations = PolicyViolation::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->whereIn('status', ['rejected', 'closed'])
            ->whereNotNull('explanation')
            ->where('created_at', '>=', Carbon::now()->subMonths(12))
            ->pluck('explanation');

        if ($rejectedExplanations->isEmpty()) {
            return [];
        }

        // Map keywords to user-facing issue descriptions
        $patterns = [
            'missing'     => ['issue' => 'Missing documentation',       'suggestion' => 'Ensure all required documents are attached before submitting.'],
            'incomplete'  => ['issue' => 'Incomplete submission',        'suggestion' => 'Review the submission checklist and fill in all required fields.'],
            'expired'     => ['issue' => 'Expired documentation',        'suggestion' => 'Check that all supporting documents are current and within validity period.'],
            'incorrect'   => ['issue' => 'Incorrect information',        'suggestion' => 'Double-check the details provided for accuracy.'],
            'approval'    => ['issue' => 'Unapproved action',            'suggestion' => 'Ensure required approvals are obtained before submission.'],
            'policy'      => ['issue' => 'Policy non-compliance',        'suggestion' => 'Review the full policy requirements before submitting a new request.'],
            'date'        => ['issue' => 'Date or timing issue',         'suggestion' => 'Submit requests with sufficient advance notice as required by policy.'],
            'signature'   => ['issue' => 'Missing signature or consent', 'suggestion' => 'Include all required signatures and signed attestation forms.'],
        ];

        $found = [];
        $seen  = [];

        foreach ($rejectedExplanations as $explanation) {
            $lower = strtolower($explanation);
            foreach ($patterns as $keyword => $info) {
                if (! isset($seen[$keyword]) && str_contains($lower, $keyword)) {
                    $found[]        = $info;
                    $seen[$keyword] = true;
                }
            }
        }

        return $found;
    }

    /**
     * Get policy-specific recommendations
     * 
     * @param Policy $policy
     * @param User $user
     * @param mixed $violations
     * @return array
     */
    private function getPolicySpecificRecommendations(Policy $policy, User $user, $violations): array
    {
        $recommendations = [];
        $violationCount  = $violations->count();
        $category        = strtolower($policy->category?->name ?? '');

        // Review date approaching
        if ($policy->review_date && $policy->review_date->diffInDays(Carbon::today()) <= 30) {
            $recommendations[] = [
                'priority' => 'low',
                'type'     => 'policy_update',
                'message'  => "{$policy->name} is due for review soon. Requirements may change.",
                'action'   => 'monitor_policy',
            ];
        }

        // Category-specific advice
        if (str_contains($category, 'leave') || str_contains($category, 'attendance')) {
            if ($violationCount > 0) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'type'     => 'pre_approval',
                    'message'  => 'Leave and attendance policies require pre-approval. Ensure your line manager is notified in advance.',
                    'action'   => 'notify_manager',
                ];
            }
        }

        if (str_contains($category, 'expense') || str_contains($category, 'finance')) {
            $recommendations[] = [
                'priority' => 'medium',
                'type'     => 'documentation',
                'message'  => 'Financial policies require original receipts and cost-centre approval. Attach all supporting invoices.',
                'action'   => 'attach_receipts',
            ];
        }

        if (str_contains($category, 'it') || str_contains($category, 'security') || str_contains($category, 'data')) {
            $recommendations[] = [
                'priority' => 'high',
                'type'     => 'compliance_check',
                'message'  => 'IT and security policies require sign-off from the IT Security team before proceeding.',
                'action'   => 'get_it_signoff',
            ];
        }

        if (str_contains($category, 'hr') || str_contains($category, 'conduct')) {
            if ($violationCount >= 2) {
                $recommendations[] = [
                    'priority' => 'high',
                    'type'     => 'hr_consultation',
                    'message'  => 'Repeated HR or conduct policy violations may result in formal disciplinary action. Consult HR before submitting.',
                    'action'   => 'consult_hr',
                ];
            }
        }

        // Escalation warning for accumulated violations
        if ($violationCount >= 5) {
            $recommendations[] = [
                'priority' => 'high',
                'type'     => 'escalation_warning',
                'message'  => "You have {$violationCount} violations under this policy. Further non-compliance may trigger automatic escalation.",
                'action'   => 'acknowledge_risk',
            ];
        }

        return $recommendations;
    }
}
