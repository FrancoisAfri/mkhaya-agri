<?php

namespace App\Modules\HR\Recruitment\Actions\Requisition;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Events\ApprovalRequested;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitRequisitionForApprovalAction
{
    public function execute(Requisition $requisition, int $userId): Requisition
    {
        if ($requisition->status !== RequisitionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => "Only draft requisitions can be submitted for approval (current: {$requisition->status->label()}).",
            ]);
        }

        $fresh = DB::transaction(function () use ($requisition) {
            $requisition->update([
                'status'       => RequisitionStatus::PendingApproval->value,
                'submitted_at' => now(),
            ]);

            return $requisition->fresh();
        });

        ApprovalRequested::dispatch($fresh, 'requisition');

        return $fresh;
    }
}
