<?php

namespace App\Modules\HR\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('contracts.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'content'     => 'nullable|string',
            'template_id' => 'nullable|integer',
        ];
    }
}
