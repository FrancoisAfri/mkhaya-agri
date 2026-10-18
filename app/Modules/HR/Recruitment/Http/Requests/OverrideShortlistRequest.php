<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideShortlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.shortlists.override') ?? false;
    }

    public function rules(): array
    {
        return [
            'candidate_id'    => 'required|exists:recruitment_candidates,id',
            'action'          => 'required|in:added,removed',
            'override_reason' => 'required|string|min:20|max:2000',
        ];
    }
}
