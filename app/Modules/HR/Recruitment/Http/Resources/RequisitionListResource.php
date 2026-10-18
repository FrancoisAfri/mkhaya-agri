<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequisitionListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'requisition_number' => $this->requisition_number,
            'title'              => $this->title,
            'department'         => $this->department,
            'job_type'           => [
                'value' => $this->job_type->value,
                'label' => $this->job_type->label(),
            ],
            'headcount'          => $this->headcount,
            'status'             => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'requested_by'       => $this->whenLoaded('requestedBy', fn () => [
                'id'   => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),
            'submitted_by'       => $this->whenLoaded('requestedBy', fn () => $this->requestedBy->name),
            'submitted_at'       => $this->submitted_at?->toDateString(),
            'created_at'         => $this->created_at->toDateString(),
        ];
    }
}
