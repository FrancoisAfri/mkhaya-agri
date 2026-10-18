<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.requisitions.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'headcount'         => 'required|integer|min:1|max:100',
            'reason'            => 'required|string|max:2000',
            'target_start_date' => 'nullable|date|after:today',
        ];
    }
}
