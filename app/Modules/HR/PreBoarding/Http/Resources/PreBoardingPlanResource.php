<?php

namespace App\Modules\HR\PreBoarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreBoardingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'uuid'         => $this->uuid,
            'status'       => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'due_date'     => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'tasks'        => PreBoardingTaskResource::collection($this->whenLoaded('tasks')),
            'created_at'   => $this->created_at->toISOString(),
        ];
    }
}
