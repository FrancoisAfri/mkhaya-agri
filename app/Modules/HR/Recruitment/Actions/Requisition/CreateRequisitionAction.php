<?php

namespace App\Modules\HR\Recruitment\Actions\Requisition;

use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Support\Facades\DB;

class CreateRequisitionAction
{
    public function execute(array $data, int $requestedBy): Requisition
    {
        return DB::transaction(function () use ($data, $requestedBy) {
            return Requisition::create([
                ...$data,
                'requisition_number' => Requisition::generateRequisitionNumber(),
                'requested_by'       => $requestedBy,
                'status'             => RequisitionStatus::Draft->value,
            ]);
        });
    }
}
