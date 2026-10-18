<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingProvisioningTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'uuid'                => $this->uuid,
            'type'                => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
                'icon'  => $this->type->icon(),
            ],
            'title'               => $this->title,
            'notes'               => $this->notes,
            'status'              => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'assigned_department' => $this->assigned_department,
            'due_date'            => $this->due_date?->toDateString(),
            'completed_at'        => $this->completed_at?->toISOString(),
            'created_at'          => $this->created_at->toISOString(),
        ];
    }
}
