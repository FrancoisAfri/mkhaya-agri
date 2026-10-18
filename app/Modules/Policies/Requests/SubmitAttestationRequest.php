<?php

namespace App\Modules\Policies\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only the assigned user may submit their own attestation
        $attestation = $this->route('attestation');
        return $attestation && (int) $attestation->user_id === (int) $this->user()?->id;
    }

    public function rules(): array
    {
        return [
            'acknowledged' => ['required', 'boolean', 'accepted'],
            'quiz_score'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'acknowledged.accepted' => 'You must confirm that you have read and understood this policy.',
        ];
    }
}
