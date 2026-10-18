<?php

namespace App\Modules\HR\Recruitment\Actions\Requisition;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveRequisitionAction
{
    public function execute(Requisition $requisition, int $approvedBy, ?string $notes = null): Requisition
    {
        if (!$requisition->status->isApprovable()) {
            throw ValidationException::withMessages([
                'status' => "Requisition must be pending approval to be approved (current: {$requisition->status->label()}).",
            ]);
        }

        return DB::transaction(function () use ($requisition, $approvedBy, $notes) {
            $requisition->update([
                'status'         => RequisitionStatus::Approved->value,
                'approved_by'    => $approvedBy,
                'approved_at'    => now(),
                'approval_notes' => $notes,
            ]);

            return $requisition->fresh();
        });
    }
}
