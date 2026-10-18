<?php

namespace App\Modules\HR\Recruitment\Actions\Requisition;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectRequisitionAction
{
    public function execute(Requisition $requisition, int $rejectedBy, string $reason): Requisition
    {
        if (!$requisition->status->isApprovable()) {
            throw ValidationException::withMessages([
                'status' => "Requisition must be pending approval to be rejected (current: {$requisition->status->label()}).",
            ]);
        }

        return DB::transaction(function () use ($requisition, $rejectedBy, $reason) {
            $requisition->update([
                'status'           => RequisitionStatus::Rejected->value,
                'rejected_by'      => $rejectedBy,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            return $requisition->fresh();
        });
    }
}
