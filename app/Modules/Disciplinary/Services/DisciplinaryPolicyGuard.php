<?php

namespace App\Modules\Disciplinary\Services;

use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryHearingOutcome;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyEnforcementLog;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyViolation;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Disciplinary module actions and the global Policy rule engine.
 *
 * Every write action in the disciplinary module passes through this guard.
 * Outcomes:
 *   block    → ValidationException (action is prevented)
 *   warn     → violation recorded, warnings returned, action proceeds
 *   escalate → violation recorded, flag returned, action proceeds
 *   log_only → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that activate disciplinary rules:
 *   module = 'Disciplinary'
 *   action_pattern examples:
 *     'disciplinary.*'
 *     'disciplinary.case.*'
 *     'disciplinary.hearing.*'
 *     'disciplinary.sanction.*'
 *     'disciplinary.warning.*'
 *     'disciplinary.chairperson.*'
 *     'disciplinary.dismissal.*'
 *     'disciplinary.investigation.*'
 *     'disciplinary.suspension.*'
 *     'disciplinary.outcome.*'
 */
class DisciplinaryPolicyGuard
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
     *
     * @param  ?int  $caseId        Disciplinary case context (for logging)
     * @param  ?int  $hearingId     Hearing context (for logging)
     * @param  ?int  $investigationId Investigation context (for logging)
     */
    public function check(
        PolicyEventDTO $event,
        ?int $caseId = null,
        ?int $hearingId = null,
        ?int $investigationId = null,
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

            // Record in module-specific violations table
            $violation = DisciplinaryPolicyViolation::create([
                'case_id'          => $caseId,
                'hearing_id'       => $hearingId,
                'investigation_id' => $investigationId,
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
                // Log before throwing
                $this->writeLog($event, $caseId, $hearingId, $investigationId,
                    count($results), count($violationIds), true, false, false,
                    $enforcementSummary, $violationIds);

                throw ValidationException::withMessages([
                    'policy' => [$enforced['blocked_message']
                        ?? "This disciplinary action is blocked by policy: {$result->rule->name}"],
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

        // Write enforcement audit log
        $this->writeLog(
            $event, $caseId, $hearingId, $investigationId,
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

    // ── Disciplinary action checks ────────────────────────────────────────────

    /**
     * Check policies when a disciplinary case is created.
     * Useful rules: gross misconduct requires formal hearing, minimum evidence requirements,
     * suspension employees cannot initiate new cases.
     */
    public function forCaseCreated(DisciplinaryCase $case, int $actorId): array
    {
        $case->loadMissing(['category', 'employee']);
        $previousCasesCount = DisciplinaryCase::where('employee_id', $case->employee_id)
            ->where('id', '!=', $case->id)
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.case.created',
            payload:     [
                'employee_id'           => $case->employee_id,
                'category_id'           => $case->category_id,
                'severity'              => $case->category?->severity_level,
                'is_gross_misconduct'   => $case->category?->severity_level === 'gross',
                'incident_date'         => $case->incident_date?->toDateString(),
                'previous_cases_count'  => $previousCasesCount,
                'actor_id'              => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    /**
     * Check policies when a chairperson is assigned to a case.
     * Useful rules: chairperson cannot be direct manager, chairperson cannot be the accused,
     * chairperson must have required training/role.
     */
    public function forChairpersonAssigned(DisciplinaryCase $case, int $chairpersonUserId, int $actorId): array
    {
        $case->loadMissing(['employee']);

        // Check if chairperson is the direct manager of the employee
        $employeePersonId = DB::table('hr_people')->where('user_id', $case->employee_id)->value('id');
        $chairpersonIsManager = false;
        if ($employeePersonId) {
            $chairpersonPersonId = DB::table('hr_people')->where('user_id', $chairpersonUserId)->value('id');
            $chairpersonIsManager = $chairpersonPersonId
                && DB::table('hr_people')->where('id', $employeePersonId)->where('manager_id', $chairpersonPersonId)->exists();
        }

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.chairperson.assigned',
            payload:     [
                'case_id'                => $case->id,
                'employee_id'            => $case->employee_id,
                'chairperson_id'         => $chairpersonUserId,
                'chairperson_is_manager' => $chairpersonIsManager,
                'chairperson_is_accused' => $chairpersonUserId === $case->employee_id,
                'severity'               => $case->category?->severity_level,
                'actor_id'               => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    /**
     * Check policies when an investigator is assigned.
     * Useful rules: investigator cannot be the accused, no conflict of interest.
     */
    public function forInvestigatorAssigned(DisciplinaryCase $case, int $investigatorUserId, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.investigator.assigned',
            payload:     [
                'case_id'               => $case->id,
                'employee_id'           => $case->employee_id,
                'investigator_id'       => $investigatorUserId,
                'investigator_is_accused' => $investigatorUserId === $case->employee_id,
                'severity'              => $case->category?->severity_level,
                'actor_id'              => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    /**
     * Check policies when a hearing is scheduled.
     * Useful rules: gross misconduct requires formal (not informal) hearing,
     * minimum notice periods, hearing cannot be same-day for formal cases.
     */
    public function forHearingScheduled(DisciplinaryHearing $hearing, int $actorId): array
    {
        $hearing->loadMissing(['case.category']);
        $daysUntilHearing = $hearing->scheduled_at
            ? (int) now()->diffInDays($hearing->scheduled_at, absolute: false)
            : null;

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.hearing.scheduled',
            payload:     [
                'case_id'              => $hearing->case_id,
                'hearing_type'         => $hearing->hearing_type,
                'is_formal'            => $hearing->hearing_type === 'formal',
                'severity'             => $hearing->case?->category?->severity_level,
                'is_gross_misconduct'  => $hearing->case?->category?->severity_level === 'gross',
                'days_until_hearing'   => $daysUntilHearing,
                'has_chairperson'      => !is_null($hearing->chairperson_id),
                'has_hr_rep'           => !is_null($hearing->hr_rep_id),
                'actor_id'             => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $hearing->id,
            subjectType: DisciplinaryHearing::class,
        ), caseId: $hearing->case_id, hearingId: $hearing->id);
    }

    /**
     * Check policies when an outcome/verdict is captured.
     * Useful rules: dismissal requires executive + HR approval, sanction proportionality,
     * final written warning limits.
     */
    public function forOutcomeCaptured(DisciplinaryHearingOutcome $outcome, int $actorId): array
    {
        $outcome->loadMissing(['hearing.case.category', 'sanction', 'outcome']);
        $case = $outcome->hearing?->case;

        // Count prior sanctions for progressive discipline check
        $priorSanctions = 0;
        $lastSanctionType = null;
        if ($case) {
            $prior = DisciplinaryHearingOutcome::whereHas('hearing', fn($q) => $q->where('case_id', $case->id))
                ->where('id', '!=', $outcome->id)
                ->with('sanction')
                ->latest()
                ->first();
            if ($prior) {
                $priorSanctions   = DisciplinaryHearingOutcome::whereHas('hearing', fn($q) => $q->where('case_id', $case->id))->where('id', '!=', $outcome->id)->count();
                $lastSanctionType = $prior->sanction?->name;
            }
        }

        $isDismissal = str_contains(strtolower($outcome->verdict ?? ''), 'dismiss')
            || str_contains(strtolower($outcome->sanction?->name ?? ''), 'dismiss');

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.outcome.captured',
            payload:     [
                'case_id'               => $case?->id,
                'hearing_id'            => $outcome->hearing_id,
                'verdict'               => $outcome->verdict,
                'sanction_type'         => $outcome->sanction?->name,
                'outcome_type'          => $outcome->outcome?->name,
                'is_dismissal'          => $isDismissal,
                'severity'              => $case?->category?->severity_level,
                'is_gross_misconduct'   => $case?->category?->severity_level === 'gross',
                'prior_sanctions_count' => $priorSanctions,
                'last_sanction_type'    => $lastSanctionType,
                'actor_id'              => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $outcome->id,
            subjectType: DisciplinaryHearingOutcome::class,
        ), caseId: $case?->id, hearingId: $outcome->hearing_id);
    }

    /**
     * Check policies when a sanction is proposed (standalone, before outcome capture).
     * Useful rules: sanction escalation — cannot jump from no warning to dismissal,
     * written warning limits per period.
     */
    public function forSanctionProposed(
        DisciplinaryCase $case,
        string $sanctionType,
        int $actorId
    ): array {
        $case->loadMissing(['category']);

        // Get the employee's recent disciplinary history for progressive discipline
        $recentSanctions = DisciplinaryHearingOutcome::whereHas('hearing.case', fn($q) => $q->where('employee_id', $case->employee_id))
            ->where('created_at', '>=', now()->subYear())
            ->with('sanction')
            ->latest()
            ->get();

        $writtenWarningsInYear = $recentSanctions->filter(fn($o) =>
            str_contains(strtolower($o->sanction?->name ?? ''), 'written warning')
        )->count();

        $hasFinalWarningActive = $recentSanctions->filter(fn($o) =>
            str_contains(strtolower($o->sanction?->name ?? ''), 'final')
            && $o->created_at?->gte(now()->subMonths(12))
        )->isNotEmpty();

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.sanction.proposed',
            payload:     [
                'case_id'                    => $case->id,
                'employee_id'                => $case->employee_id,
                'sanction_type'              => $sanctionType,
                'is_dismissal'               => str_contains(strtolower($sanctionType), 'dismiss'),
                'is_final_warning'           => str_contains(strtolower($sanctionType), 'final'),
                'severity'                   => $case->category?->severity_level,
                'written_warnings_in_year'   => $writtenWarningsInYear,
                'has_active_final_warning'   => $hasFinalWarningActive,
                'prior_sanctions_count'      => $recentSanctions->count(),
                'actor_id'                   => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    /**
     * Check warning expiry policies.
     * Useful rules: written warning expires after 6 months, final written warning after 12 months.
     * Call before using a prior warning as the basis for escalation.
     */
    public function forWarningExpiry(
        DisciplinaryCase $case,
        string $warningType,  // 'written_warning' | 'final_written_warning'
        \Carbon\Carbon $warningIssuedAt,
        int $actorId
    ): array {
        $ageMonths        = (int) $warningIssuedAt->diffInMonths(now());
        $expiryMonths     = $warningType === 'final_written_warning' ? 12 : 6;
        $isExpired        = $ageMonths >= $expiryMonths;

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.warning.expiry_check',
            payload:     [
                'case_id'         => $case->id,
                'employee_id'     => $case->employee_id,
                'warning_type'    => $warningType,
                'issued_at'       => $warningIssuedAt->toDateString(),
                'age_months'      => $ageMonths,
                'expiry_months'   => $expiryMonths,
                'is_expired'      => $isExpired,
                'actor_id'        => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    /**
     * Check suspension policy when actioning a suspended employee.
     * Useful rules: suspended employees cannot approve workflows, cannot access certain systems,
     * suspension cannot exceed legal maximum days.
     */
    public function forSuspensionAction(
        int $employeeId,
        string $actionType,  // 'suspend' | 'reinstate' | 'action_while_suspended'
        ?int $suspensionDays,
        int $actorId,
        ?int $caseId = null,
    ): array {
        $currentlySuspended = DisciplinaryCase::where('employee_id', $employeeId)
            ->whereHas('status', fn($q) => $q->where('name', 'like', '%suspend%'))
            ->exists();

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.suspension.action',
            payload:     [
                'employee_id'         => $employeeId,
                'action_type'         => $actionType,
                'suspension_days'     => $suspensionDays,
                'currently_suspended' => $currentlySuspended,
                'exceeds_30_days'     => $suspensionDays && $suspensionDays > 30,
                'exceeds_60_days'     => $suspensionDays && $suspensionDays > 60,
                'actor_id'            => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $employeeId,
        ), caseId: $caseId);
    }

    /**
     * Check progressive discipline policy — is the proposed action proportionate
     * given the employee's history?
     * Useful rules: cannot jump from no history to dismissal for minor offences,
     * lateness threshold triggers formal warning.
     */
    public function forProgressiveDiscipline(
        DisciplinaryCase $case,
        string $proposedAction,
        int $actorId
    ): array {
        $case->loadMissing(['category', 'employee']);
        $history = DisciplinaryCase::where('employee_id', $case->employee_id)
            ->where('id', '!=', $case->id)
            ->with(['category', 'status'])
            ->orderByDesc('incident_date')
            ->get();

        $repeatedOffenceCount = $history->filter(fn($c) => $c->category_id === $case->category_id)->count();

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.progressive_discipline.check',
            payload:     [
                'case_id'                => $case->id,
                'employee_id'            => $case->employee_id,
                'category_id'            => $case->category_id,
                'severity'               => $case->category?->severity_level,
                'proposed_action'        => $proposedAction,
                'total_prior_cases'      => $history->count(),
                'repeated_offence_count' => $repeatedOffenceCount,
                'is_first_offence'       => $history->isEmpty(),
                'actor_id'               => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    /**
     * Check policies when a case is closed.
     * Useful rules: case cannot be closed without outcome, dismissal closure needs HR sign-off.
     */
    public function forCaseClosed(DisciplinaryCase $case, ?string $outcome, int $actorId): array
    {
        $case->loadMissing(['hearings', 'investigations']);
        $hasCompletedHearing     = $case->hearings->whereIn('status', ['completed'])->isNotEmpty();
        $hasCompletedInvestigation = $case->investigations->where('status', 'completed')->isNotEmpty();
        $isDismissal = str_contains(strtolower($outcome ?? ''), 'dismiss');

        return $this->check(new PolicyEventDTO(
            module:      'Disciplinary',
            action:      'disciplinary.case.closed',
            payload:     [
                'case_id'                    => $case->id,
                'employee_id'                => $case->employee_id,
                'outcome'                    => $outcome,
                'is_dismissal'               => $isDismissal,
                'severity'                   => $case->category?->severity_level,
                'has_completed_hearing'      => $hasCompletedHearing,
                'has_completed_investigation'=> $hasCompletedInvestigation,
                'actor_id'                   => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $case->id,
            subjectType: DisciplinaryCase::class,
        ), caseId: $case->id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function classifyViolationType(string $action): string
    {
        return match(true) {
            str_contains($action, 'warning.expiry')      => 'warning_expiry',
            str_contains($action, 'suspension')          => 'suspension_action',
            str_contains($action, 'hearing')             => 'hearing_requirement',
            str_contains($action, 'dismissal')           => 'dismissal_approval',
            str_contains($action, 'chairperson')         => 'chairperson_conflict',
            str_contains($action, 'sanction')            => 'sanction_limit',
            str_contains($action, 'progressive')         => 'progressive_discipline',
            default                                      => 'custom',
        };
    }

    private function writeLog(
        PolicyEventDTO $event,
        ?int $caseId,
        ?int $hearingId,
        ?int $investigationId,
        int $rulesEvaluated,
        int $violationsFound,
        bool $wasBlocked,
        bool $requiresEscalation,
        bool $passedClean,
        array $enforcementSummary,
        array $violationIds,
    ): void {
        DisciplinaryPolicyEnforcementLog::create([
            'case_id'            => $caseId,
            'hearing_id'         => $hearingId,
            'investigation_id'   => $investigationId,
            'action_checked'     => $event->action,
            'rules_evaluated'    => $rulesEvaluated,
            'violations_found'   => $violationsFound,
            'was_blocked'        => $wasBlocked,
            'required_escalation'=> $requiresEscalation,
            'passed_clean'       => $passedClean,
            'payload'            => $event->payload,
            'enforcement_summary'=> $enforcementSummary,
            'violation_ids'      => $violationIds,
            'actor_id'           => $event->actorId,
            'checked_at'         => now(),
        ]);
    }
}
