<?php

namespace App\Modules\HR\Offers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'status'          => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'position_title'  => $this->position_title,
            'department'      => $this->department,
            'start_date'      => $this->start_date?->toDateString(),
            'salary_amount'   => $this->salary_amount,
            'salary_currency' => $this->salary_currency,
            'contract_type'   => $this->contract_type,
            'notes'           => $this->notes,
            'expires_at'      => $this->expires_at?->toIso8601String(),
            'sent_at'         => $this->sent_at?->toIso8601String(),
            'accepted_at'     => $this->accepted_at?->toIso8601String(),
            'declined_at'     => $this->declined_at?->toIso8601String(),
            'decline_reason'  => $this->decline_reason,

            // Relations — only loaded when eager-loaded
            'candidate' => $this->whenLoaded('candidate', fn() => [
                'id'        => $this->candidate->id,
                'uuid'      => $this->candidate->uuid,
                'full_name' => $this->candidate->full_name,
                'email'     => $this->candidate->email,
            ]),
            'job' => $this->whenLoaded('job', fn() => [
                'id'    => $this->job->id,
                'title' => $this->job->title,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn() => [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'approved_by' => $this->whenLoaded('approvedBy', fn() => $this->approvedBy ? [
                'id'   => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
