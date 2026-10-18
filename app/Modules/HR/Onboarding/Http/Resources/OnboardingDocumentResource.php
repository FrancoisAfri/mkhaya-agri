<?php

namespace App\Modules\HR\Onboarding\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'document_type'    => [
                'value' => $this->document_type->value,
                'label' => $this->document_type->label(),
                'icon'  => $this->document_type->icon(),
            ],
            'label'            => $this->label,
            'is_required'      => $this->is_required,
            'status'           => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'file_name'        => $this->file_name,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at'      => $this->reviewed_at?->toISOString(),
            'created_at'       => $this->created_at->toISOString(),
        ];
    }
}
