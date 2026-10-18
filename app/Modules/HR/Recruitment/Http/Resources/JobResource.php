<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'reference_number' => $this->reference_number,
            'description'      => $this->description,
            'requirements'     => $this->requirements,
            'responsibilities' => $this->responsibilities,
            'department'       => $this->whenLoaded('companyUnit', fn() => $this->companyUnit->name),
            'location'         => $this->location,
            'contract_type'    => $this->employment_type,
            'headcount'        => $this->whenLoaded('requisitions',
                fn() => (int) $this->requisitions->sum('headcount') ?: 1,
                1,
            ),
            'status'           => $this->is_active
                ? ($this->closes_at?->isPast() ? 'closed' : 'active')
                : ($this->posted_at ? 'filled' : 'draft'),
            'salary_range'     => $this->salary_min
                ? ['min' => $this->salary_min, 'max' => $this->salary_max, 'currency' => $this->currency]
                : null,
            'posted_at'          => $this->posted_at?->toIso8601String(),
            'closes_at'          => $this->closes_at?->toIso8601String(),
            'applications_count' => $this->whenCounted('applications'),
            'pipeline_stages'    => $this->whenLoaded('pipelineStages', fn() =>
                $this->pipelineStages->map(fn($s) => [
                    'id'          => $s->id,
                    'name'        => $s->name,
                    'type'        => $s->type->value,
                    'order_index' => $s->order_index,
                ])
            ),
            'created_by' => $this->whenLoaded('createdBy', fn() => [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
