<?php

namespace App\Modules\Policies\Services\Enforcement;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Services\RuleEngine\SeverityScorer;
use App\Modules\Policies\Services\EnforcementEngine\{ViolationRecorder, ActionDispatcher};
use Illuminate\Support\Facades\DB;

/**
 * EnforcementActionHandler
 * 
 * Orchestrates the enforcement response to policy violations.
 * 
 * Flow:
 * 1. Receives evaluation result (pass/violation)
 * 2. Calculate severity score
 * 3. Generate explanation
 * 4. Record violation in database
 * 5. Dispatch enforcement action (block/warn/escalate)
 * 6. Trigger notifications according to severity
 * 7. Return result to caller
 * 
 * Enforcement Actions:
 * - block: Throw exception to prevent action
 * - warn: Allow action but notify user and escalate to manager
 * - escalate: Allow action but require manager approval workflow
 * - log_only: Allow action but audit trail created
 */
class EnforcementActionHandler
{
    public function __construct(
        private readonly SeverityScorer $severityScorer,
        private readonly PolicyExplanationEngine $explanationEngine,
        private readonly ViolationRecorder $violationRecorder,
        private readonly ActionDispatcher $actionDispatcher,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {}

    /**
     * Handle a rule evaluation result
     * 
     * @param EvaluationResultDTO $result
     * @return array Response with violation details and next steps
     */
    public function handle(EvaluationResultDTO $result): array
    {
        return DB::transaction(function () use ($result) {
            // If rule passed, no enforcement needed
            if ($result->passed) {
                return $this->handlePass($result);
            }

            // Calculate severity of violation
            $severityScore = $this->severityScorer->calculateSeverityScore($result);

            // Generate explanation for user
            $explanation = $this->explanationEngine->generateExplanation($result);

            // Record violation in database
            $violation = $this->recordViolation($result, $severityScore, $explanation);

            // Dispatch enforcement action
            $this->actionDispatcher->dispatch($result, $violation);

            // Send notifications based on severity and action
            $this->notificationDispatcher->dispatch(
                result: $result,
                violation: $violation,
                severityScore: $severityScore,
                explanation: $explanation
            );

            // A violation-aware explanation for the UI. EvaluationResultDTO::$explanation is
            // readonly, so surface it via the return array instead of mutating the DTO.
            $uiExplanation = $this->explanationEngine->generateExplanation($result, $violation->id);

            return [
                'passed' => false,
                'violation_id' => $violation->id,
                'severity_score' => $severityScore,
                'severity_level' => $this->severityScorer->categorizeScore($severityScore),
                'enforcement_action' => $result->outcome,
                'is_blocked' => $result->outcome === 'block',
                'requires_approval' => $result->outcome === 'escalate',
                'explanation' => $uiExplanation,
                'blocked_message' => $this->getBlockedMessage($result, $violation),
            ];
        });
    }

    /**
     * Handle when rule passes
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function handlePass(EvaluationResultDTO $result): array
    {
        return [
            'passed' => true,
            'violation_id' => null,
            'enforced_action' => 'allowed',
            'message' => 'Action allowed. Policy check passed.',
        ];
    }

    /**
     * Record violation in database with all context
     * 
     * @param EvaluationResultDTO $result
     * @param int $severityScore
     * @param array $explanation
     * @return PolicyViolation
     */
    private function recordViolation(
        EvaluationResultDTO $result,
        int $severityScore,
        array $explanation
    ): PolicyViolation {
        // Generate detailed explanation text
        $explanationText = $this->generateViolationExplanation($result, $explanation, $severityScore);

        // Record using existing recorder
        $violation = $this->violationRecorder->record($result);

        // Update with severity score and enriched explanation
        $violation->update([
            'severity_score' => $severityScore,
            'explanation' => $explanationText,
            'metadata' => json_encode([
                'policy_id' => $result->rule->policy_id,
                'policy_name' => $result->rule->policy->name,
                'clause_id' => $result->rule->clause_id,
                'clause_title' => $result->rule->clause?->title,
                'event_module' => $result->event->module,
                'event_action' => $result->event->action,
                'actor_id' => $result->event->actorId,
            ]),
        ]);

        return $violation;
    }

    /**
     * Generate detailed explanation text for violation record
     * 
     * @param EvaluationResultDTO $result
     * @param array $explanation
     * @param int $severityScore
     * @return string
     */
    private function generateViolationExplanation(
        EvaluationResultDTO $result,
        array $explanation,
        int $severityScore
    ): string {
        $text = "Violation: {$result->rule->name}\n";
        $text .= "Policy: {$result->rule->policy->name}\n";
        $text .= "Severity: {$this->severityScorer->getLabel($severityScore)} (score: $severityScore)\n";
        $text .= "Action: " . strtoupper($result->outcome) . "\n\n";

        if ($result->rule->clause) {
            $text .= "Based on clause: {$result->rule->clause->title}\n\n";
        }

        $text .= "Event Details:\n";
        $text .= "Module: {$result->event->module}\n";
        $text .= "Action: {$result->event->action}\n";

        if (!empty($result->event->payload)) {
            $text .= "Data: " . json_encode($result->event->payload) . "\n";
        }

        return $text;
    }

    /**
     * Get message to show to user when action is blocked
     * 
     * @param EvaluationResultDTO $result
     * @param PolicyViolation $violation
     * @return string
     */
    private function getBlockedMessage(EvaluationResultDTO $result, PolicyViolation $violation): string
    {
        $policy = $result->rule->policy->name;
        $rule = $result->rule->name;

        $message = "Your action violates the $policy policy: $rule";

        if ($result->rule->requires_exception_approval) {
            $message .= "\n\nYou can request an exception to proceed. "
                . "Your request will be reviewed by your manager.";
        }

        if ($result->rule->policy->owner) {
            $message .= "\n\nFor more information, contact: "
                . $result->rule->policy->owner->name;
        }

        return $message;
    }
}
