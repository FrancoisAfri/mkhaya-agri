<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.jobs.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'            => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:50|unique:recruitment_jobs,reference_number',
            'company_unit_id'      => 'nullable|integer',
            'location'         => 'nullable|string|max:255',
            'employment_type'  => 'required|in:full_time,part_time,contract,internship',
            'description'      => 'nullable|string|max:10000',
            'requirements'     => 'nullable|string|max:10000',
            'responsibilities' => 'nullable|string|max:10000',
            'salary_min'       => 'nullable|numeric|min:0',
            'salary_max'       => 'nullable|numeric|min:0|gte:salary_min',
            'currency'         => 'nullable|string|size:3',
            'posted_at'        => 'nullable|date',
            'closes_at'        => 'nullable|date|after:posted_at',
        ];
    }
}
