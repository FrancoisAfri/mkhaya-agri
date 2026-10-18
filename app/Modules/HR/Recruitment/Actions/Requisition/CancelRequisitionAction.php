<?php

namespace App\Modules\HR\Recruitment\Actions\Requisition;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelRequisitionAction
{
    public function execute(Requisition $requisition, int $cancelledBy): Requisition
    {
        if ($requisition->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => "Requisition is already in a terminal state and cannot be cancelled (current: {$requisition->status->label()}).",
            ]);
        }

        return DB::transaction(function () use ($requisition) {
            $requisition->update([
                'status'       => RequisitionStatus::Cancelled->value,
                'cancelled_at' => now(),
            ]);

            return $requisition->fresh();
        });
    }
}
