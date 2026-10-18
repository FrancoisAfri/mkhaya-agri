<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InternalVacancyResource extends JsonResource
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
            'job_type'       => $this->job_type ? [
                'value' => $this->job_type->value,
                'label' => $this->job_type->label(),
            ] : null,
            'headcount'               => $this->headcount,
            'job_description'         => $this->job_description,
            'salary_band_min'         => $this->salary_band_min,
            'salary_band_max'         => $this->salary_band_max,
            'expires_at'              => $this->expires_at?->toDateString(),
            'published_internally_at' => $this->published_internally_at?->toDateString(),
        ];
    }
}
