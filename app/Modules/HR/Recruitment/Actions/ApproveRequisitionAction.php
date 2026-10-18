<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Events\RequisitionApproved;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ApproveRequisitionAction
{
    use PolicyValidationTrait;

    public function execute(Requisition $requisition, int $approverId, ?string $comments = null): Requisition
    {
        // Self-approval is not permitted
        if ($approverId === $requisition->requested_by) {
            throw ValidationException::withMessages([
                'approver_id' => 'You cannot approve your own requisition.',
            ]);
        }

        if ($requisition->status !== RequisitionStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'status' => "Requisition is not pending approval (current: {$requisition->status->label()}).",
            ]);
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'requisition.approved',
            payload: [
                'requisition_id' => $requisition->id,
                'job_id'         => $requisition->job_id,
                'approver_id'    => $approverId,
                'headcount'      => $requisition->headcount,
            ],
            userId: $approverId,
        );

        return DB::transaction(function () use ($requisition, $approverId, $comments) {
            $requisition->update([
                'status'      => RequisitionStatus::Approved->value,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'requisition.approved',
                subjectId:   $requisition->id,
                subjectType: Requisition::class,
                payload:     [
                    'approver_id' => $approverId,
                    'comments'    => $comments,
                    'job_id'      => $requisition->job_id,
                ],
                userId: $approverId,
            );

            Event::dispatch(new RequisitionApproved($requisition->fresh()));

            return $requisition->fresh();
        });
    }
}
