<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaApprovalRequest;
use App\Modules\PerformanceAppraisal\Models\PaCalibrationSession;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use App\Modules\PerformanceAppraisal\Models\PaKpiScore;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Models\PaPipPlan;
use App\Modules\PerformanceAppraisal\Models\PaProbationReview;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Performance Appraisal module actions and the global Policy rule engine.
 *
 * Every write action in the PA module (scoring, feedback, probation outcomes,
 * PIP lifecycle, calibration, approvals) passes through this guard before
 * hitting the database.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger PA rules:
 *  module = 'PerformanceAppraisal'
 *  action_pattern examples: 'performance_appraisal.*', 'performance_appraisal.kpi.*'
 */
class PerformanceAppraisalPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against an event.
     *
     * Throws ValidationException if any rule outcome is 'block'.
     * Returns warnings / violation ids / escalation flag for callers to surface.
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
     * Check policies when a KPI score is recorded for an employee.
     */
    public function forKpiScored(PaEmployeeKpi $kpi, PaKpiScore $score, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.kpi.scored',
            payload:     [
                'employee_id'  => $kpi->employee_id,
                'cycle_id'     => $kpi->cycle_id,
                'kpi_id'       => $kpi->id,
                'score_pct'    => $score->score_pct,
                'rating'       => $score->rating,
                'scored_by'    => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $kpi->employee_id,
            subjectType: PaEmployeeKpi::class,
        ));
    }

    /**
     * Check policies when feedback is submitted about an employee.
     */
    public function forFeedbackSubmitted(PaFeedback $feedback, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.feedback.submitted',
            payload:     [
                'employee_id'    => $feedback->employee_id,
                'reviewer_id'    => $feedback->reviewer_id,
                'feedback_type'  => $feedback->feedback_type,
                'rating'         => $feedback->rating,
                'cycle_id'       => $feedback->cycle_id,
            ],
            actorId:     $actorId,
            subjectId:   $feedback->employee_id,
            subjectType: PaFeedback::class,
        ));
    }

    /**
     * Check policies when a check-in is scheduled or completed.
     */
    public function forCheckinCompleted(PaCheckin $checkin, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.checkin.completed',
            payload:     [
                'employee_id'  => $checkin->employee_id,
                'manager_id'   => $checkin->manager_id,
                'cycle_id'     => $checkin->cycle_id ?? null,
                'overall_mood' => $checkin->overall_mood ?? null,
                'checkin_date' => $checkin->scheduled_date ?? null,
            ],
            actorId:     $actorId,
            subjectId:   $checkin->employee_id,
            subjectType: PaCheckin::class,
        ));
    }

    /**
     * Check policies when a probation review outcome is finalised.
     */
    public function forProbationFinalised(PaProbationReview $review, string $outcome, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.probation.finalised',
            payload:     [
                'employee_id'  => $review->employee_id,
                'review_id'    => $review->id,
                'outcome'      => $outcome,
                'review_date'  => $review->review_date ?? null,
                'reviewer_id'  => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $review->employee_id,
            subjectType: PaProbationReview::class,
        ));
    }

    /**
     * Check policies when a PIP plan is created for an employee.
     */
    public function forPipCreated(PaPipPlan $plan, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.pip.created',
            payload:     [
                'employee_id'    => $plan->employee_id,
                'manager_id'     => $plan->manager_id,
                'cycle_id'       => $plan->cycle_id ?? null,
                'start_date'     => $plan->start_date,
                'target_end_date' => $plan->target_end_date,
                'reason'         => $plan->reason,
            ],
            actorId:     $actorId,
            subjectId:   $plan->employee_id,
            subjectType: PaPipPlan::class,
        ));
    }

    /**
     * Check policies when a PIP plan is closed out (improved, not improved, escalated).
     */
    public function forPipClosed(PaPipPlan $plan, string $status, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.pip.closed',
            payload:     [
                'employee_id'      => $plan->employee_id,
                'pip_id'           => $plan->id,
                'close_status'     => $status,
                'overall_progress' => $plan->overallProgress(),
                'days_remaining'   => $plan->daysRemaining(),
            ],
            actorId:     $actorId,
            subjectId:   $plan->employee_id,
            subjectType: PaPipPlan::class,
        ));
    }

    /**
     * Check policies when a calibration session is finalised.
     */
    public function forCalibrationFinalized(PaCalibrationSession $session, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.calibration.finalised',
            payload:     [
                'session_id'  => $session->id,
                'cycle_id'    => $session->cycle_id,
                'title'       => $session->title,
                'finalized_by' => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $session->id,
            subjectType: PaCalibrationSession::class,
        ));
    }

    /**
     * Check policies when an approval request action is taken (approve / reject / recall).
     */
    public function forApprovalAction(PaApprovalRequest $request, string $action, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.approval.' . $action,
            payload:     [
                'request_id'   => $request->id,
                'workflow_id'  => $request->workflow_id,
                'subject_type' => $request->approvable_type,
                'subject_id'   => $request->approvable_id,
                'current_step' => $request->current_step,
                'total_steps'  => $request->total_steps,
                'action'       => $action,
            ],
            actorId:     $actorId,
            subjectId:   $request->id,
            subjectType: PaApprovalRequest::class,
        ));
    }

    /**
     * Check policies when a cycle is activated or closed.
     */
    public function forCycleStatusChanged(int $cycleId, string $newStatus, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:   'PerformanceAppraisal',
            action:   'performance_appraisal.cycle.' . $newStatus,
            payload:  [
                'cycle_id'   => $cycleId,
                'new_status' => $newStatus,
            ],
            actorId:  $actorId,
            subjectId: $cycleId,
        ));
    }

    /**
     * Check policies when an employee submits their self-assessment.
     */
    public function forAppraisalSubmitted(PaPerformanceSnapshot $snapshot, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.appraisal.submitted',
            payload:     [
                'snapshot_id'    => $snapshot->id,
                'employee_id'    => $snapshot->employee_id,
                'cycle_id'       => $snapshot->cycle_id,
                'employee_score' => $snapshot->employee_score,
                'overall_score'  => $snapshot->overall_score,
            ],
            actorId:     $actorId,
            subjectId:   $snapshot->employee_id,
            subjectType: PaPerformanceSnapshot::class,
        ));
    }

    /**
     * Check policies when a manager approves a final appraisal.
     */
    public function forAppraisalApproved(PaPerformanceSnapshot $snapshot, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PerformanceAppraisal',
            action:      'performance_appraisal.appraisal.approved',
            payload:     [
                'snapshot_id'   => $snapshot->id,
                'employee_id'   => $snapshot->employee_id,
                'cycle_id'      => $snapshot->cycle_id,
                'final_score'   => $snapshot->final_score,
                'manager_score' => $snapshot->manager_score,
                'score_360'     => $snapshot->score_360,
                'approved_by'   => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $snapshot->employee_id,
            subjectType: PaPerformanceSnapshot::class,
        ));
    }
}
