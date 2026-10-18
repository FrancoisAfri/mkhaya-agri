<?php

namespace App\Modules\HR\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('contracts.send') ?? false;
    }

    public function rules(): array
    {
        return [
            'signatories'                  => 'required|array|min:1',
            'signatories.*.type'           => 'required|string|in:candidate,hr_manager,witness',
            'signatories.*.user_id'        => 'nullable|integer|exists:users,id',
            'signatories.*.candidate_id'   => 'nullable|integer|exists:recruitment_candidates,id',
        ];
    }
}
