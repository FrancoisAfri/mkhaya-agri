<?php

namespace App\Modules\HR\Recruitment\Http\Requests\Vacancy;

use App\Modules\HR\Recruitment\Enums\VacancyType;
use App\Modules\HR\Recruitment\Enums\VacancyVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVacancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $visibilityValues = array_column(VacancyVisibility::cases(), 'value');
        $jobTypeValues    = array_column(VacancyType::cases(), 'value');

        return [
            'title'                  => ['required', 'string', 'max:200'],
            'department'             => ['nullable', 'string', 'max:100'],
            'visibility'             => ['required', Rule::in($visibilityValues)],
            'job_type'               => ['required', Rule::in($jobTypeValues)],
            'headcount'              => ['required', 'integer', 'min:1', 'max:50'],
            'job_description'        => ['required', 'string'],
            'required_qualifications' => ['nullable', 'string'],
            'required_skills'        => ['nullable', 'array'],
            'required_skills.*'      => ['string'],
            'salary_band_min'        => ['nullable', 'numeric', 'min:0'],
            'salary_band_max'        => ['nullable', 'numeric', 'gte:salary_band_min'],
            'expires_at'             => ['nullable', 'date', 'after:today'],
            'internal_deadline'      => [
                'required_if:visibility,internal_first_then_external',
                'nullable',
                'date',
                'after:today',
            ],
            'requisition_id' => ['nullable', 'exists:recruitment_requisitions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'internal_deadline.required_if' => 'An internal deadline is required when using "Internal First, Then External" visibility.',
            'salary_band_max.gte'           => 'The maximum salary must be greater than or equal to the minimum salary.',
        ];
    }
}
