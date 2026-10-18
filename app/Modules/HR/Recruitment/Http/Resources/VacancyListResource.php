<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyListResource extends JsonResource
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

            'job_type'   => $this->job_type?->label(),
            'headcount'  => $this->headcount,
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
