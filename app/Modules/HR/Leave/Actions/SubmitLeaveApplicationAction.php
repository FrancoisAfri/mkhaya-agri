<?php

namespace App\Modules\HR\Leave\Actions;

use App\Modules\HR\Leave\Models\LeaveApplication;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;

/**
 * SubmitLeaveApplicationAction
 * 
 * Demonstrates policy validation integration for Leave module.
 * When a leave request is submitted, it is validated against any
 * applicable Leave policies before being saved.
 * 
 * Usage:
 * ```php
 * $action = app(SubmitLeaveApplicationAction::class);
 * $leave = $action->execute([
 *     'user_id' => 42,
 *     'leave_type_id' => 1,
 *     'start_date' => '2026-05-15',
 *     'end_date' => '2026-05-20',
 *     'reason' => 'Annual leave',
 * ]);
 * ```
 */
class SubmitLeaveApplicationAction
{
    use PolicyValidationTrait;

    /**
     * Submit a leave application with policy validation
     * 
     * @param array $data Leave application data
     * @return LeaveApplication
     * @throws \Illuminate\Validation\ValidationException if policy validation fails
     */
    public function execute(array $data): LeaveApplication
    {
        return DB::transaction(function () use ($data) {
            // Step 1: Validate against policies BEFORE creation
            // This will throw an exception if action is blocked by policy
            $this->validatePolicyAction(
                module: 'Leave',
                action: 'leave_request.submitted',
                payload: $data,
                userId: $data['user_id']
            );

            // Step 2: Create the leave application
            $leave = LeaveApplication::create([
                'user_id' => $data['user_id'],
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
            ]);

            // Step 3: Dispatch policy event for audit trail
            // This records the action and checks for violations (warnings, etc.)
            $this->dispatchPolicyEvent(
                module: 'Leave',
                action: 'leave_request.submitted',
                subjectId: $leave->id,
                subjectType: LeaveApplication::class,
                payload: [
                    'leave_type_id' => $data['leave_type_id'],
                    'days_requested' => $leave->days_count,
                    'start_date' => $leave->start_date->toDateString(),
                    'end_date' => $leave->end_date->toDateString(),
                ],
                userId: $data['user_id']
            );

            return $leave;
        });
    }
}
