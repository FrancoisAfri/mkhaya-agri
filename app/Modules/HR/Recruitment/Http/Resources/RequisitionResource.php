<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'uuid'                    => $this->uuid,
            'requisition_number'      => $this->requisition_number,
            'title'                   => $this->title,
            'department'              => $this->department,
            'position_id'             => $this->position_id,
            'reporting_to'            => $this->reporting_to,
            'job_type'                => [
                'value' => $this->job_type->value,
                'label' => $this->job_type->label(),
            ],
            'headcount'               => $this->headcount,
            'reason'                  => $this->reason,
            'job_description'         => $this->job_description,
            'required_qualifications' => $this->required_qualifications,
            'required_skills'         => $this->required_skills ?? [],
            'salary_band_min'         => $this->salary_band_min,
            'salary_band_max'         => $this->salary_band_max,
            'target_start_date'       => $this->target_start_date?->toDateString(),
            'status'                  => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'submitted_at'    => $this->submitted_at?->toIso8601String(),
            'approved_at'     => $this->approved_at?->toIso8601String(),
            'rejected_at'     => $this->rejected_at?->toIso8601String(),
            'converted_at'    => $this->converted_at?->toIso8601String(),
            'cancelled_at'    => $this->cancelled_at?->toIso8601String(),
            'approval_notes'  => $this->approval_notes,
            'rejection_reason' => $this->rejection_reason,
            'requested_by'    => $this->whenLoaded('requestedBy', fn () => [
                'id'   => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),
            'approved_by'     => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ]),
            'rejected_by'     => $this->whenLoaded('rejectedBy', fn () => [
                'id'   => $this->rejectedBy->id,
                'name' => $this->rejectedBy->name,
            ]),
            'vacancy'         => $this->whenLoaded('vacancy', fn () => $this->vacancy
                ? [
                    'id'             => $this->vacancy->id,
                    'uuid'           => $this->vacancy->uuid,
                    'vacancy_number' => $this->vacancy->vacancy_number,
                    'status'         => [
                        'value' => $this->vacancy->status->value,
                        'label' => $this->vacancy->status->label(),
                        'color' => $this->vacancy->status->color(),
                    ],
                ]
                : null
            ),
            'created_at'      => $this->created_at->toIso8601String(),
            'updated_at'      => $this->updated_at->toIso8601String(),
        ];
    }
}
