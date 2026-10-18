<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.candidates.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'email'         => 'required|email|max:255|unique:recruitment_candidates,email',
            'phone'         => 'nullable|string|max:20',
            'id_number'     => 'nullable|string|max:30',
            'nationality'   => 'nullable|string|max:100',
            'gender'        => 'nullable|in:male,female,non_binary,prefer_not_to_say',
            'date_of_birth' => 'nullable|date|before:today',
            'address'       => 'nullable|string|max:1000',
            'linkedin_url'  => 'nullable|url|max:500',
        ];
    }
}
