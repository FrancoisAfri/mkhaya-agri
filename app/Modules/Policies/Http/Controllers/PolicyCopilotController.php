<?php

namespace App\Modules\Policies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Policies\Services\Copilot\PolicyCopilot;
use App\Modules\Policies\Services\Copilot\DecisionAdvisor;
use Illuminate\Http\Request;

/**
 * PolicyCopilotController
 * 
 * API endpoints for AI policy assistant and decision advisor
 */
class PolicyCopilotController extends Controller
{
    private PolicyCopilot $copilot;
    private DecisionAdvisor $advisor;

    public function __construct(PolicyCopilot $copilot, DecisionAdvisor $advisor)
    {
        $this->copilot = $copilot;
        $this->advisor = $advisor;
    }

    /**
     * Ask question about policies
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function askQuestion(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string|min:5|max:500',
            'policy_id' => 'nullable|integer|exists:policies,id',
        ]);

        $answer = $this->copilot->askQuestion(
            question: $validated['question'],
            policyId: $validated['policy_id'] ?? null
        );

        return response()->json([
            'data' => $answer,
        ]);
    }

    /**
     * Get policy information
     * 
     * @param \App\Modules\Policies\Models\Policy $policy
     * @return \Illuminate\Http\Response
     */
    public function getPolicy(\App\Modules\Policies\Models\Policy $policy)
    {
        $result = $this->copilot->getPolicy($policy->id);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Search policies
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function searchPolicies(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:200',
        ]);

        $results = $this->copilot->searchPolicies($validated['query']);

        return response()->json([
            'data' => $results,
        ]);
    }

    /**
     * Get policies by topic
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getPoliciesByTopic(Request $request)
    {
        $validated = $request->validate([
            'topic' => 'required|string|min:2|max:100',
        ]);

        $policies = $this->copilot->getPoliciesByTopic($validated['topic']);

        return response()->json([
            'data' => $policies,
        ]);
    }

    /**
     * Advise on proposed action
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function adviseOnAction(Request $request)
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|exists:policies,id',
            'action_data' => 'required|array',
        ]);

        $advice = $this->advisor->adviseOnAction(
            userId: auth()->id(),
            policyId: $validated['policy_id'],
            actionData: $validated['action_data']
        );

        return response()->json([
            'data' => $advice,
        ]);
    }

    /**
     * Predict outcome of submission
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function predictOutcome(Request $request)
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|exists:policies,id',
            'submission_data' => 'required|array',
        ]);

        $prediction = $this->advisor->predictOutcome(
            userId: auth()->id(),
            policyId: $validated['policy_id'],
            submissionData: $validated['submission_data']
        );

        return response()->json([
            'data' => $prediction,
        ]);
    }

    /**
     * Get compliance recommendations
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getComplianceRecommendations(Request $request)
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|exists:policies,id',
        ]);

        $recommendations = $this->advisor->getComplianceRecommendations(
            userId: auth()->id(),
            policyId: $validated['policy_id']
        );

        return response()->json([
            'data' => $recommendations,
        ]);
    }

    /**
     * Get top rejection reasons for policy
     * 
     * @param \App\Modules\Policies\Models\Policy $policy
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getRejectionReasons(\App\Modules\Policies\Models\Policy $policy, Request $request)
    {
        $limit = $request->query('limit', 5);

        $reasons = $this->advisor->getRejectionReasons($policy->id, $limit);

        return response()->json([
            'data' => $reasons,
            'count' => count($reasons),
        ]);
    }

    /**
     * Chat with copilot (simpler interface)
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|min:3|max:500',
            'context' => 'nullable|array',
        ]);

        // Determine intent from message
        $message = strtolower($validated['message']);

        if (strpos($message, 'can i') !== false || strpos($message, 'am i allowed') !== false) {
            // Compliance question
            $policyId = $this->extractPolicyIdFromMessage($message);
            $answer = $this->copilot->askQuestion($message, $policyId);
        } else if (strpos($message, 'what is') !== false || strpos($message, 'explain') !== false) {
            // Policy explanation
            $policyId = $this->extractPolicyIdFromMessage($message);
            if ($policyId) {
                $answer = [
                    'type' => 'policy_info',
                    'data' => $this->copilot->getPolicy($policyId),
                ];
            } else {
                $answer = $this->copilot->askQuestion($message);
            }
        } else {
            // General question
            $answer = $this->copilot->askQuestion($message);
        }

        return response()->json([
            'data' => $answer,
        ]);
    }

    /**
     * Extract policy ID from message (naive implementation)
     * 
     * @param string $message
     * @return int|null
     */
    private function extractPolicyIdFromMessage(string $message): ?int
    {
        // Look for numbers in message
        if (preg_match('/policy[:\s]+(\d+)/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
