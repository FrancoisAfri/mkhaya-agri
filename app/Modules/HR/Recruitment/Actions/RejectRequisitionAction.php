<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectRequisitionAction
{
    use PolicyValidationTrait;

    public function execute(Requisition $requisition, int $rejectorId, string $reason): Requisition
    {
        if ($requisition->status !== RequisitionStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'status' => "Requisition is not pending approval (current: {$requisition->status->label()}).",
            ]);
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'requisition.rejected',
            payload: [
                'requisition_id' => $requisition->id,
                'rejector_id'    => $rejectorId,
                'reason'         => $reason,
            ],
            userId: $rejectorId,
        );

        return DB::transaction(function () use ($requisition, $rejectorId, $reason) {
            $requisition->update([
                'status'          => RequisitionStatus::Rejected->value,
                'rejected_reason' => $reason,
            ]);

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'requisition.rejected',
                subjectId:   $requisition->id,
                subjectType: Requisition::class,
                payload:     ['rejector_id' => $rejectorId, 'reason' => $reason],
                userId:      $rejectorId,
            );

            return $requisition->fresh();
        });
    }
}
