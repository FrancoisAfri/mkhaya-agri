<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'type'  => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
                'icon'  => $this->type->icon(),
            ],
            'title'        => $this->title,
            'description'  => $this->description,
            'status'       => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'due_date'     => $this->due_date?->toDateString(),
            'assigned_to'  => $this->assigned_to,
            'completed_at' => $this->completed_at?->toISOString(),
            'sort_order'   => $this->sort_order,
        ];
    }
}
