<?php

namespace App\Modules\HR\Leave\Contracts;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\Policies\DTOs\PolicyEventDTO;

interface LeavePolicyServiceContract
{
    /**
     * Evaluate an arbitrary leave policy event.
     * Returns ['warnings' => [], 'violation_ids' => [], 'requires_escalation' => bool].
     * Throws ValidationException on block outcome.
     */
    public function check(PolicyEventDTO $event): array;

    /**
     * Check all applicable policies before a leave request is submitted.
     */
    public function forSubmission(
        HRPerson              $employee,
        LeaveType             $leaveType,
        SubmitLeaveRequestDTO $dto,
        float                 $workingDays,
        float                 $workingHours,
        float                 $currentBalanceHours,
        int                   $actorUserId,
    ): array;

    /**
     * Check policies when an approver approves a leave request.
     */
    public function forApproval(LeaveRequest $request, int $approverUserId): array;

    /**
     * Check policies when an approver rejects a leave request.
     */
    public function forRejection(LeaveRequest $request, string $reason, int $approverUserId): array;

    /**
     * Check policies when a leave request is cancelled.
     */
    public function forCancellation(LeaveRequest $request, string $reason, int $actorUserId): array;

    /**
     * Check policies when a document is uploaded against a leave request.
     */
    public function forDocumentUpload(LeaveRequest $request, string $documentType, int $actorUserId): array;

    /**
     * Check policies when a manual balance override is applied.
     */
    public function forBalanceOverride(int $hrPersonId, int $leaveTypeId, float $overrideHours, int $actorUserId): array;
}
