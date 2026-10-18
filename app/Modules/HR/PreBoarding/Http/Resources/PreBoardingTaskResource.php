<?php

namespace App\Modules\HR\PreBoarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreBoardingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'type'        => [
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
            'completed_at' => $this->completed_at?->toISOString(),
            'sort_order'   => $this->sort_order,
            'owner_role'   => $this->owner_role,
            'assigned_to'  => $this->assignedTo ? ['id' => $this->assignedTo->id, 'name' => $this->assignedTo->name] : null,
        ];
    }
}
