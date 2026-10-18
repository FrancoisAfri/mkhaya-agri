<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class MoveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.applications.move') ?? false;
    }

    public function rules(): array
    {
        return [
            'status'            => ['required', new Enum(ApplicationStatus::class)],
            'pipeline_stage_id' => 'nullable|exists:recruitment_pipeline_stages,id',
            'reason'            => 'nullable|string|max:2000',
        ];
    }
}
