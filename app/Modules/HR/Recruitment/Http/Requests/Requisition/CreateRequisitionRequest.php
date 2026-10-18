<?php

namespace App\Modules\HR\Recruitment\Http\Requests\Requisition;

use App\Modules\HR\Recruitment\Enums\VacancyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.requisitions.create') ?? false;
    }

    public function rules(): array
    {
        $jobTypeValues = implode(',', array_column(VacancyType::cases(), 'value'));

        return [
            'title'                   => ['required', 'string', 'max:200'],
            'department'              => ['nullable', 'string', 'max:100'],
            'position_id'             => ['nullable', 'integer', 'exists:hr_positions,id'],
            'reporting_to'            => ['nullable', 'integer', 'exists:users,id'],
            'job_type'                => ['required', new Enum(VacancyType::class)],
            'headcount'               => ['required', 'integer', 'min:1', 'max:50'],
            'reason'                  => ['required', 'string'],
            'job_description'         => ['nullable', 'string'],
            'required_qualifications' => ['nullable', 'string'],
            'required_skills'         => ['nullable', 'array'],
            'required_skills.*'       => ['string'],
            'salary_band_min'         => ['nullable', 'numeric', 'min:0'],
            'salary_band_max'         => ['nullable', 'numeric', 'gte:salary_band_min'],
            'target_start_date'       => ['nullable', 'date', 'after:today'],
        ];
    }
}
