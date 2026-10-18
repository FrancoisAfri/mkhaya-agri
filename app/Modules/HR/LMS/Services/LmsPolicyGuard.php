<?php

namespace App\Modules\HR\LMS\Services;

use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsPolicyEnforcementLog;
use App\Modules\HR\LMS\Models\LmsPolicyException;
use App\Modules\HR\LMS\Models\LmsPolicyViolation;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAttempt;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between LMS module actions and the global Policy rule engine.
 *
 * Every significant write action in the LMS passes through this guard.
 * Outcomes:
 *   block    → ValidationException (action is prevented)
 *   warn     → violation recorded, warnings returned, action proceeds
 *   escalate → violation recorded, flag returned, action proceeds
 *   log_only → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that activate LMS rules:
 *   module = 'LMS'
 *   action_pattern examples:
 *     'lms.*'
 *     'lms.enrollment.*'
 *     'lms.course.*'
 *     'lms.certificate.*'
 *     'lms.assessment.*'
 *     'lms.mandatory.*'
 *     'lms.understanding.*'
 */
class LmsPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    // ── Core evaluation ───────────────────────────────────────────────────────

    /**
     * Evaluate all matching policy rules for the given event.
     * Throws ValidationException if any rule outcome is 'block'.
     * Returns [warnings[], violation_ids[], requires_escalation].
     */
    public function check(
        PolicyEventDTO $event,
        ?int $enrollmentId  = null,
        ?int $assignmentId  = null,
        ?int $attemptId     = null,
    ): array {
        $results = $this->ruleEngine->evaluate($event);

        $warnings           = [];
        $violationIds       = [];
        $enforcementSummary = [];
        $wasBlocked         = false;
        $requiresEscalation = false;

        foreach ($results as $result) {
            /** @var EvaluationResultDTO $result */
            if ($result->passed) {
                $enforcementSummary[] = ['rule' => $result->rule->name, 'outcome' => 'passed', 'severity' => $result->severity];
                continue;
            }

            $enforced = $this->enforcementHandler->handle($result);

            $actionTaken = match(true) {
                $enforced['is_blocked'] ?? false         => 'blocked',
                $enforced['requires_approval'] ?? false  => 'escalated',
                str_contains((string) ($enforced['enforcement_action'] ?? ''), 'warn') => 'warned',
                default                                  => 'logged',
            };

            $violation = LmsPolicyViolation::create([
                'enrollment_id'    => $enrollmentId,
                'assignment_id'    => $assignmentId,
                'attempt_id'       => $attemptId,
                'policy_violation_id' => $enforced['violation_id'] ?? null,
                'rule_name'        => $result->rule->name,
                'violation_type'   => $this->classifyViolationType($event->action),
                'severity'         => $result->severity ?? 'medium',
                'action_taken'     => $actionTaken,
                'description'      => is_array($enforced['explanation'] ?? null)
                    ? ($enforced['explanation']['summary'] ?? $result->rule->name)
                    : (string) ($enforced['explanation'] ?? $result->rule->name),
                'payload_snapshot' => $event->payload,
                'actor_id'         => $event->actorId,
            ]);

            $violationIds[] = $violation->id;

            $enforcementSummary[] = [
                'rule'        => $result->rule->name,
                'outcome'     => $actionTaken,
                'severity'    => $result->severity,
                'explanation' => is_array($enforced['explanation'] ?? null)
                    ? ($enforced['explanation']['summary'] ?? '')
                    : (string) ($enforced['explanation'] ?? ''),
            ];

            if ($enforced['is_blocked'] ?? false) {
                $wasBlocked = true;
                $this->writeLog($event, $enrollmentId, $assignmentId, $attemptId,
                    count($results), count($violationIds), true, false, false,
                    $enforcementSummary, $violationIds);

                throw ValidationException::withMessages([
                    'policy' => [$enforced['blocked_message']
                        ?? "This LMS action is blocked by policy: {$result->rule->name}"],
                ]);
            }

            if ($enforced['requires_approval'] ?? false) {
                $requiresEscalation = true;
            }

            $explanation = $enforced['explanation'] ?? null;
            if ($explanation) {
                $warnings[] = is_array($explanation)
                    ? ($explanation['summary'] ?? $result->rule->name)
                    : (string) $explanation;
            }
        }

        $this->writeLog(
            $event, $enrollmentId, $assignmentId, $attemptId,
            count($results), count($violationIds),
            $wasBlocked, $requiresEscalation, empty($violationIds),
            $enforcementSummary, $violationIds
        );

        return [
            'warnings'            => $warnings,
            'violation_ids'       => $violationIds,
            'requires_escalation' => $requiresEscalation,
        ];
    }

    // ── LMS action checks ─────────────────────────────────────────────────────

    /**
     * Check policies when an employee enrolls in a course.
     * Useful rules: mandatory prerequisites must be met, max concurrent enrollments,
     * certification still valid (no re-enroll if cert is current).
     */
    public function forCourseEnrollment(LmsCourse $course, int $userId, int $actorId): array
    {
        $course->loadMissing(['category']);

        $existingEnrollment = LmsEnrollment::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->first();

        $activeEnrollmentCount = LmsEnrollment::where('user_id', $userId)
            ->whereIn('status', ['enrolled', 'in_progress'])
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'LMS',
            action:      'lms.enrollment.created',
            payload:     [
                'user_id'                  => $userId,
                'course_id'                => $course->id,
                'course_title'             => $course->title,
                'course_type'              => $course->type,
                'is_mandatory'             => $course->is_mandatory,
                'already_enrolled'         => !is_null($existingEnrollment),
                'previous_status'          => $existingEnrollment?->status,
                'active_enrollments_count' => $activeEnrollmentCount,
                'actor_id'                 => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $userId,
            subjectType: 'user',
        ));
    }

    /**
     * Check policies when a course is completed (quiz passed or all lessons done).
     * Useful rules: minimum score thresholds, certificate auto-issue rules.
     */
    public function forCourseCompletion(LmsEnrollment $enrollment, int $actorId): array
    {
        $enrollment->loadMissing(['course']);

        return $this->check(new PolicyEventDTO(
            module:      'LMS',
            action:      'lms.course.completed',
            payload:     [
                'user_id'      => $enrollment->user_id,
                'course_id'    => $enrollment->course_id,
                'course_title' => $enrollment->course?->title,
                'score'        => $enrollment->score,
                'pass_score'   => $enrollment->course?->pass_score,
                'passed'       => $enrollment->status === 'completed',
                'actor_id'     => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $enrollment->id,
            subjectType: LmsEnrollment::class,
        ), enrollmentId: $enrollment->id);
    }

    /**
     * Check policies when a questionnaire/assessment is submitted.
     * Useful rules: pass mark requirements, max retakes exceeded, time limit compliance.
     */
    public function forAssessmentSubmission(LmsQuestionnaireAttempt $attempt, int $actorId): array
    {
        $attempt->loadMissing(['questionnaire']);

        $attemptCount = LmsQuestionnaireAttempt::where('questionnaire_id', $attempt->questionnaire_id)
            ->where('user_id', $attempt->user_id)
            ->whereIn('status', ['completed', 'passed', 'failed', 'pending_review'])
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'LMS',
            action:      'lms.assessment.submitted',
            payload:     [
                'user_id'          => $attempt->user_id,
                'questionnaire_id' => $attempt->questionnaire_id,
                'attempt_number'   => $attemptCount,
                'score'            => $attempt->score,
                'pass_score'       => $attempt->questionnaire?->pass_score,
                'passed'           => $attempt->passed,
                'status'           => $attempt->status,
                'actor_id'         => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $attempt->id,
            subjectType: LmsQuestionnaireAttempt::class,
        ), attemptId: $attempt->id);
    }

    /**
     * Check policies when a certificate is requested or auto-issued.
     * Useful rules: course pass mark must be met, certificate not already valid,
     * expiry enforcement — re-certification required before access to restricted actions.
     */
    public function forCertificateRequested(LmsEnrollment $enrollment, int $actorId): array
    {
        $enrollment->loadMissing(['course', 'certificate']);

        $existingCert = $enrollment->certificate;
        $certExpired  = $existingCert && $existingCert->expires_at && $existingCert->expires_at->isPast();

        return $this->check(new PolicyEventDTO(
            module:      'LMS',
            action:      'lms.certificate.requested',
            payload:     [
                'user_id'              => $enrollment->user_id,
                'course_id'            => $enrollment->course_id,
                'course_title'         => $enrollment->course?->title,
                'enrollment_status'    => $enrollment->status,
                'score'                => $enrollment->score,
                'pass_score'           => $enrollment->course?->pass_score,
                'has_existing_cert'    => !is_null($existingCert),
                'cert_expired'         => $certExpired,
                'expires_after_days'   => $enrollment->course?->expires_after_days,
                'actor_id'             => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $enrollment->id,
            subjectType: LmsEnrollment::class,
        ), enrollmentId: $enrollment->id);
    }

    /**
     * Check policies when a manager confirms an understanding score.
     * Useful rules: understanding threshold — minimum score before action is allowed,
     * score must be confirmed within N days of assessment.
     */
    public function forManagerConfirmation(int $scoreId, int $userId, float $score, float $threshold, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'LMS',
            action:      'lms.understanding.evaluated',
            payload:     [
                'understanding_score_id'  => $scoreId,
                'user_id'                 => $userId,
                'score'                   => $score,
                'threshold'               => $threshold,
                'below_threshold'         => $score < $threshold,
                'actor_id'                => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $userId,
            subjectType: 'user',
        ));
    }

    /**
     * Check policies when an employee has overdue mandatory training.
     * Useful rules: blocked actions list enforcement, escalation thresholds,
     * escalate to line manager after N days overdue.
     */
    public function forMandatoryTrainingCheck(LmsComplianceAssignment $assignment, int $actorId): array
    {
        $assignment->loadMissing(['rule', 'user']);

        $daysOverdue = $assignment->daysOverdue();

        return $this->check(new PolicyEventDTO(
            module:      'LMS',
            action:      'lms.mandatory.overdue',
            payload:     [
                'user_id'          => $assignment->user_id,
                'course_id'        => $assignment->course_id,
                'rule_id'          => $assignment->rule_id,
                'rule_name'        => $assignment->rule?->name,
                'due_date'         => $assignment->due_date?->toDateString(),
                'days_overdue'     => $daysOverdue,
                'status'           => $assignment->status,
                'is_overdue'       => $daysOverdue > 0,
                'restrict_actions' => $assignment->rule?->restrict_actions ?? [],
                'actor_id'         => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $assignment->user_id,
            subjectType: 'user',
        ), assignmentId: $assignment->id);
    }

    // ── Exception management ──────────────────────────────────────────────────

    /**
     * Request an exception for a policy violation, allowing an action to proceed
     * pending HR/manager approval.
     */
    public function requestTrainingPolicyException(
        int $userId,
        string $contextType,
        ?int $contextId,
        string $reason,
        ?int $ruleId,
        int $requestedBy,
        ?string $validUntil = null,
    ): LmsPolicyException {
        return LmsPolicyException::create([
            'user_id'       => $userId,
            'rule_id'       => $ruleId,
            'context_type'  => $contextType,
            'context_id'    => $contextId,
            'reason'        => $reason,
            'valid_from'    => today(),
            'valid_until'   => $validUntil,
            'status'        => 'pending',
            'requested_by'  => $requestedBy,
        ]);
    }

    /**
     * Check whether the user has an active approved exception for this context.
     */
    public function hasActiveException(int $userId, string $contextType, ?int $contextId = null): bool
    {
        return LmsPolicyException::where('user_id', $userId)
            ->where('context_type', $contextType)
            ->where('status', 'approved')
            ->where('valid_from', '<=', today())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()))
            ->when($contextId, fn ($q) => $q->where('context_id', $contextId))
            ->exists();
    }

    /**
     * Track a violation without running the full rule engine (for direct recording).
     */
    public function trackTrainingPolicyViolation(
        string $violationType,
        string $description,
        int $actorId,
        array $payload = [],
        string $severity = 'medium',
        ?int $enrollmentId = null,
        ?int $assignmentId = null,
    ): LmsPolicyViolation {
        return LmsPolicyViolation::create([
            'enrollment_id'  => $enrollmentId,
            'assignment_id'  => $assignmentId,
            'rule_name'      => $violationType,
            'violation_type' => $violationType,
            'severity'       => $severity,
            'action_taken'   => 'logged',
            'description'    => $description,
            'payload_snapshot' => $payload,
            'actor_id'       => $actorId,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function classifyViolationType(string $action): string
    {
        return match(true) {
            str_contains($action, 'mandatory')    => 'mandatory_training_incomplete',
            str_contains($action, 'certificate')  => 'cert_expired',
            str_contains($action, 'assessment')   => 'pass_mark_not_met',
            str_contains($action, 'understanding')=> 'understanding_below_threshold',
            str_contains($action, 'restricted')   => 'restricted_action_blocked',
            default                               => 'custom',
        };
    }

    private function writeLog(
        PolicyEventDTO $event,
        ?int $enrollmentId,
        ?int $assignmentId,
        ?int $attemptId,
        int $rulesEvaluated,
        int $violationsFound,
        bool $wasBlocked,
        bool $requiresEscalation,
        bool $passedClean,
        array $enforcementSummary,
        array $violationIds,
    ): void {
        LmsPolicyEnforcementLog::create([
            'enrollment_id'       => $enrollmentId,
            'assignment_id'       => $assignmentId,
            'attempt_id'          => $attemptId,
            'action_checked'      => $event->action,
            'rules_evaluated'     => $rulesEvaluated,
            'violations_found'    => $violationsFound,
            'was_blocked'         => $wasBlocked,
            'required_escalation' => $requiresEscalation,
            'passed_clean'        => $passedClean,
            'payload'             => $event->payload,
            'enforcement_summary' => $enforcementSummary,
            'violation_ids'       => $violationIds,
            'actor_id'            => $event->actorId,
            'checked_at'          => now(),
        ]);
    }
}
