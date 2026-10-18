<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.jobs.edit') ?? false;
    }

    public function rules(): array
    {
        $jobId = $this->route('job')?->id;

        return [
            'title'            => 'sometimes|string|max:255',
            'reference_number' => "sometimes|string|max:50|unique:recruitment_jobs,reference_number,{$jobId}",
            'company_unit_id'      => 'nullable|integer',
            'location'         => 'nullable|string|max:255',
            'employment_type'  => 'sometimes|in:full_time,part_time,contract,internship',
            'description'      => 'nullable|string|max:10000',
            'requirements'     => 'nullable|string|max:10000',
            'responsibilities' => 'nullable|string|max:10000',
            'salary_min'       => 'nullable|numeric|min:0',
            'salary_max'       => 'nullable|numeric|min:0|gte:salary_min',
            'currency'         => 'nullable|string|size:3',
            'is_active'        => 'sometimes|boolean',
            'posted_at'        => 'nullable|date',
            'closes_at'        => 'nullable|date|after:posted_at',
        ];
    }
}
