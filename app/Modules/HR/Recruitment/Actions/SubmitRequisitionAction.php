<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;

class SubmitRequisitionAction
{
    use PolicyValidationTrait;

    public function execute(Requisition $requisition, int $userId): Requisition
    {
        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'requisition.submitted',
            payload: [
                'requisition_id' => $requisition->id,
                'job_id'         => $requisition->job_id,
                'headcount'      => $requisition->headcount,
                'user_id'        => $userId,
            ],
            userId: $userId,
        );

        return DB::transaction(function () use ($requisition, $userId) {
            $requisition->update(['status' => RequisitionStatus::PendingApproval->value]);

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'requisition.submitted',
                subjectId:   $requisition->id,
                subjectType: Requisition::class,
                payload:     ['job_id' => $requisition->job_id, 'headcount' => $requisition->headcount],
                userId:      $userId,
            );

            return $requisition->fresh();
        });
    }
}
