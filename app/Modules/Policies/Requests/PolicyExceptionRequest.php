<?php

namespace App\Modules\Policies\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PolicyExceptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'policy_id' => ['required', 'integer', 'exists:policy,id'],
            'rule_id' => ['nullable', 'integer', 'exists:policy_rules,id'],
            'reason' => ['required', 'string', 'min:20'],
            'evidence' => ['nullable', 'string'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
}
