<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProbationPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'start_date'        => $this->start_date?->toDateString(),
            'end_date'          => $this->end_date?->toDateString(),
            'extended_end_date' => $this->extended_end_date?->toDateString(),
            'duration_months'   => $this->duration_months,
            'status'            => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'outcome'           => $this->outcome,
            'outcome_notes'     => $this->outcome_notes,
            'next_review_date'  => $this->next_review_date?->toDateString(),
            'reviews'           => ProbationReviewResource::collection($this->whenLoaded('reviews')),
            'created_at'        => $this->created_at->toISOString(),
        ];
    }
}
