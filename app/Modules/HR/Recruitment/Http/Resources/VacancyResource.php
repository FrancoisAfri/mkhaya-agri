<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Modules\HR\Recruitment\Models\Vacancy $this */
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'vacancy_number' => $this->vacancy_number,
            'title'          => $this->title,
            'department'     => $this->department,

            'visibility' => $this->visibility ? [
                'value'       => $this->visibility->value,
                'label'       => $this->visibility->label(),
                'color'       => $this->visibility->color(),
                'description' => $this->visibility->description(),
            ] : null,

            'status' => $this->status ? [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ] : null,

            'job_type' => $this->job_type ? [
                'value' => $this->job_type->value,
                'label' => $this->job_type->label(),
            ] : null,

            'headcount'               => $this->headcount,
            'job_description'         => $this->job_description,
            'required_qualifications' => $this->required_qualifications,
            'required_skills'         => $this->required_skills ?? [],
            'salary_band_min'         => $this->salary_band_min,
            'salary_band_max'         => $this->salary_band_max,

            'internal_deadline' => $this->internal_deadline?->toDateString(),
            'expires_at'        => $this->expires_at?->toDateString(),

            'public_application_url'     => $this->publicApplicationUrl(),
            'published_internally_at'    => $this->published_internally_at?->toIso8601String(),
            'published_externally_at'    => $this->published_externally_at?->toIso8601String(),
            'transitioned_to_external_at' => $this->transitioned_to_external_at?->toIso8601String(),
            'closed_at'                  => $this->closed_at?->toIso8601String(),
            'created_at'                 => $this->created_at?->toIso8601String(),

            'requisition' => $this->whenLoaded('requisition'),
            'created_by'  => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),

            // Computed permissions for the UI
            'can_publish' => in_array($this->status, [
                VacancyStatus::Draft,
                VacancyStatus::OnHold,
            ]),
            'can_close' => $this->status?->isActive() ?? false,
            'can_transition' => $this->status === VacancyStatus::InternalOnly
                && $this->visibility === VacancyVisibility::InternalFirstThenExternal,
        ];
    }
}
