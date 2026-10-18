<?php

namespace App\Modules\HR\Leave\Actions;

use App\Modules\HR\Leave\Models\LeaveApplication;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;

/**
 * ApproveLeaveApplicationAction
 * 
 * Demonstrates policy validation for approval workflows.
 * When approving leave, it checks against Attendance policies.
 */
class ApproveLeaveApplicationAction
{
    use PolicyValidationTrait;

    /**
     * Approve leave application with policy validation
     * 
     * @param LeaveApplication $leave
     * @param int $approverId
     * @param string|null $comments
     * @return LeaveApplication
     * @throws \Illuminate\Validation\ValidationException if policy validation fails
     */
    public function execute(LeaveApplication $leave, int $approverId, ?string $comments = null): LeaveApplication
    {
        return DB::transaction(function () use ($leave, $approverId, $comments) {
            // Validate against policies
            $this->validatePolicyAction(
                module: 'Leave',
                action: 'leave_request.approved',
                payload: [
                    'leave_id' => $leave->id,
                    'user_id' => $leave->user_id,
                    'approver_id' => $approverId,
                    'leave_type_id' => $leave->leave_type_id,
                    'days_approved' => $leave->days_count,
                ],
                userId: $approverId
            );

            // Update leave
            $leave->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approved_at' => now(),
                'approval_comments' => $comments,
            ]);

            // Dispatch policy event
            $this->dispatchPolicyEvent(
                module: 'Leave',
                action: 'leave_request.approved',
                subjectId: $leave->id,
                subjectType: LeaveApplication::class,
                payload: [
                    'approver_id' => $approverId,
                    'days_approved' => $leave->days_count,
                    'comments' => $comments,
                ],
                userId: $approverId
            );

            return $leave;
        });
    }
}
