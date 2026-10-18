<?php

namespace App\Modules\HR\Recruitment\Services;

use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Recruitment module actions and the global Policy rule engine.
 *
 * Covers requisition lifecycle, application handling, shortlisting, offer
 * decisions, interview scoring and candidate management.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Recruitment rules:
 *  module = 'Recruitment'
 *  action_pattern examples: 'recruitment.*', 'recruitment.requisition.*', 'recruitment.offer.*'
 */
class RecruitmentPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against an event.
     *
     * Throws ValidationException if any rule outcome is 'block'.
     */
    public function check(PolicyEventDTO $event): array
    {
        $results = $this->ruleEngine->evaluate($event);

        $warnings           = [];
        $violationIds       = [];
        $requiresEscalation = false;

        foreach ($results as $result) {
            /** @var EvaluationResultDTO $result */
            if ($result->passed) {
                continue;
            }

            $enforced = $this->enforcementHandler->handle($result);

            if ($enforced['is_blocked'] ?? false) {
                throw ValidationException::withMessages([
                    'policy' => [$enforced['blocked_message']
                        ?? "This action is blocked by policy: {$result->rule->name}"],
                ]);
            }

            if ($enforced['requires_approval'] ?? false) {
                $requiresEscalation = true;
            }

            if (isset($enforced['violation_id'])) {
                $violationIds[] = $enforced['violation_id'];
            }

            $explanation = $enforced['explanation'] ?? null;
            if ($explanation) {
                $warnings[] = is_array($explanation)
                    ? ($explanation['summary'] ?? $result->rule->name)
                    : (string) $explanation;
            }
        }

        return [
            'warnings'            => $warnings,
            'violation_ids'       => $violationIds,
            'requires_escalation' => $requiresEscalation,
        ];
    }

    // ── Action-specific helpers ────────────────────────────────────────────────

    /**
     * Check policies when a job requisition is submitted for approval.
     */
    public function forRequisitionSubmitted(Requisition $requisition, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.requisition.submitted',
            payload:     [
                'requisition_id'  => $requisition->id,
                'job_id'          => $requisition->job_id,
                'department_id'   => $requisition->department_id ?? null,
                'headcount'       => $requisition->headcount ?? 1,
                'requested_by'    => $actorId,
                'employment_type' => $requisition->employment_type ?? null,
            ],
            actorId:     $actorId,
            subjectId:   $requisition->id,
            subjectType: Requisition::class,
        ));
    }

    /**
     * Check policies when a requisition is approved.
     */
    public function forRequisitionApproved(Requisition $requisition, int $approverUserId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.requisition.approved',
            payload:     [
                'requisition_id' => $requisition->id,
                'job_id'         => $requisition->job_id,
                'headcount'      => $requisition->headcount ?? 1,
                'department_id'  => $requisition->department_id ?? null,
            ],
            actorId:     $approverUserId,
            subjectId:   $requisition->id,
            subjectType: Requisition::class,
        ));
    }

    /**
     * Check policies when a candidate application is received.
     */
    public function forApplicationReceived(Application $application, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.application.received',
            payload:     [
                'application_id' => $application->id,
                'job_id'         => $application->job_id,
                'candidate_id'   => $application->candidate_id,
                'source'         => $application->source ?? null,
            ],
            actorId:     $actorId,
            subjectId:   $application->id,
            subjectType: Application::class,
        ));
    }

    /**
     * Check policies when an application is moved to a new pipeline stage.
     */
    public function forApplicationMoved(
        Application $application,
        string      $fromStage,
        string      $toStage,
        int         $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.application.stage_changed',
            payload:     [
                'application_id' => $application->id,
                'job_id'         => $application->job_id,
                'candidate_id'   => $application->candidate_id,
                'from_stage'     => $fromStage,
                'to_stage'       => $toStage,
            ],
            actorId:     $actorId,
            subjectId:   $application->id,
            subjectType: Application::class,
        ));
    }

    /**
     * Check policies when an application is rejected.
     */
    public function forApplicationRejected(Application $application, string $reason, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.application.rejected',
            payload:     [
                'application_id' => $application->id,
                'job_id'         => $application->job_id,
                'candidate_id'   => $application->candidate_id,
                'reason'         => $reason,
            ],
            actorId:     $actorId,
            subjectId:   $application->id,
            subjectType: Application::class,
        ));
    }

    /**
     * Check policies when an employment offer is extended to a candidate.
     */
    public function forOfferExtended(
        int    $candidateId,
        int    $jobId,
        float  $offerSalary,
        string $offerDate,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.offer.extended',
            payload:     [
                'candidate_id' => $candidateId,
                'job_id'       => $jobId,
                'offer_salary' => $offerSalary,
                'offer_date'   => $offerDate,
            ],
            actorId:     $actorId,
            subjectId:   $candidateId,
            subjectType: Candidate::class,
        ));
    }

    /**
     * Check policies when an offer is accepted by a candidate.
     */
    public function forOfferAccepted(
        int    $candidateId,
        int    $jobId,
        float  $acceptedSalary,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.offer.accepted',
            payload:     [
                'candidate_id'    => $candidateId,
                'job_id'          => $jobId,
                'accepted_salary' => $acceptedSalary,
            ],
            actorId:     $actorId,
            subjectId:   $candidateId,
            subjectType: Candidate::class,
        ));
    }

    /**
     * Check policies when an interview is scheduled.
     */
    public function forInterviewScheduled(
        int    $candidateId,
        int    $jobId,
        string $interviewType,
        string $scheduledAt,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.interview.scheduled',
            payload:     [
                'candidate_id'   => $candidateId,
                'job_id'         => $jobId,
                'interview_type' => $interviewType,
                'scheduled_at'   => $scheduledAt,
            ],
            actorId:     $actorId,
            subjectId:   $candidateId,
            subjectType: Candidate::class,
        ));
    }

    /**
     * Check policies when a shortlist is overridden (bypassing AI ranking).
     */
    public function forShortlistOverridden(
        int    $jobId,
        int    $candidateId,
        string $overrideReason,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Recruitment',
            action:      'recruitment.shortlist.overridden',
            payload:     [
                'job_id'          => $jobId,
                'candidate_id'    => $candidateId,
                'override_reason' => $overrideReason,
            ],
            actorId:     $actorId,
            subjectId:   $candidateId,
            subjectType: Candidate::class,
        ));
    }
}
