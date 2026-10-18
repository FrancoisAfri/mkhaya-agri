<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Leave module actions and the global Policy rule engine.
 *
 * For every leave action (submit, approve, reject, cancel, upload, override)
 * this class builds a PolicyEventDTO and passes it through the engine.
 *
 * Outcomes:
 *  block      → ValidationException (action is prevented)
 *  warn       → violation recorded, warnings returned, action proceeds
 *  escalate   → violation recorded, flag returned, action proceeds
 *  log_only   → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Leave rules:
 *  module = 'Leave'
 *  action_pattern examples: 'leave_request.*', 'leave_request.submitted', 'leave_balance.override'
 */
class LeavePolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against an event.
     *
     * Returns an array of warnings/escalation flags for the caller to surface.
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

            // Block outcome — stop the action entirely
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

            // Collect human-readable warning from the explanation
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
     * Check policies before a leave request is submitted.
     * Called after BCEA and leave-specific policy checks, before DB persist.
     */
    public function forSubmission(
        HRPerson              $employee,
        LeaveType             $leaveType,
        SubmitLeaveRequestDTO $dto,
        float                 $workingDays,
        float                 $workingHours,
        float                 $currentBalanceHours,
        int                   $actorUserId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Leave',
            action:      'leave_request.submitted',
            payload:     [
                'leave_type_id'     => $leaveType->id,
                'leave_type_name'   => $leaveType->name,
                'statutory_type'    => $leaveType->statutory_type,
                'start_date'        => $dto->startDate,
                'end_date'          => $dto->endDate,
                'working_days'      => $workingDays,
                'working_hours'     => $workingHours,
                'is_paid'           => (bool) $leaveType->is_paid,
                'is_overtime'       => (bool) $dto->isOvertime,
                'balance_hours'     => $currentBalanceHours,
                'employee_id'       => $employee->id,
                'department_id'     => $employee->company_unit_id ?? null,
            ],
            actorId:     $actorUserId,
            subjectType: LeaveRequest::class,
        ));
    }

    /**
     * Check policies when an approver approves a leave request.
     */
    public function forApproval(LeaveRequest $request, int $approverUserId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Leave',
            action:      'leave_request.approved',
            payload:     [
                'leave_type_id' => $request->leave_type_id,
                'working_days'  => $request->working_days,
                'working_hours' => $request->working_hours,
                'employee_id'   => $request->employee_id,
                'status'        => $request->status->value,
            ],
            actorId:     $approverUserId,
            subjectId:   $request->id,
            subjectType: LeaveRequest::class,
        ));
    }

    /**
     * Check policies when an approver rejects a leave request.
     */
    public function forRejection(LeaveRequest $request, string $reason, int $approverUserId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Leave',
            action:      'leave_request.rejected',
            payload:     [
                'leave_type_id' => $request->leave_type_id,
                'working_days'  => $request->working_days,
                'employee_id'   => $request->employee_id,
                'reason'        => $reason,
            ],
            actorId:     $approverUserId,
            subjectId:   $request->id,
            subjectType: LeaveRequest::class,
        ));
    }

    /**
     * Check policies when an employee cancels their own leave request.
     */
    public function forCancellation(LeaveRequest $request, string $reason, int $actorUserId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Leave',
            action:      'leave_request.cancelled',
            payload:     [
                'leave_type_id' => $request->leave_type_id,
                'working_days'  => $request->working_days,
                'employee_id'   => $request->employee_id,
                'reason'        => $reason,
                'status'        => $request->status->value,
            ],
            actorId:     $actorUserId,
            subjectId:   $request->id,
            subjectType: LeaveRequest::class,
        ));
    }

    /**
     * Check policies when a document is uploaded against a leave request.
     */
    public function forDocumentUpload(
        LeaveRequest $request,
        string       $documentType,
        int          $actorUserId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Leave',
            action:      'leave_request.document_uploaded',
            payload:     [
                'leave_type_id' => $request->leave_type_id,
                'document_type' => $documentType,
                'employee_id'   => $request->employee_id,
            ],
            actorId:     $actorUserId,
            subjectId:   $request->id,
            subjectType: LeaveRequest::class,
        ));
    }

    /**
     * Check policies when a manual balance override is applied.
     */
    public function forBalanceOverride(
        int   $hrPersonId,
        int   $leaveTypeId,
        float $overrideHours,
        int   $actorUserId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:   'Leave',
            action:   'leave_balance.override',
            payload:  [
                'employee_id'    => $hrPersonId,
                'leave_type_id'  => $leaveTypeId,
                'override_hours' => $overrideHours,
            ],
            actorId:  $actorUserId,
        ));
    }
}
