<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingTemplateTaskResource extends JsonResource
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
            'title'       => $this->title,
            'description' => $this->description,
            'day_offset'  => $this->day_offset,
            'owner_type'  => $this->owner_type,
            'sort_order'  => $this->sort_order,
        ];
    }
}
