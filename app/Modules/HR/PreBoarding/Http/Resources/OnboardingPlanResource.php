<?php

namespace App\Modules\HR\PreBoarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'status'     => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'start_date'  => $this->start_date?->toDateString(),
            'end_date'    => $this->end_date?->toDateString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'completion'   => $this->completionPercentage(),
            'created_at'   => $this->created_at->toISOString(),
        ];
    }
}
