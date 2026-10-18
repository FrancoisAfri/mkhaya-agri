<?php

namespace App\Modules\HR\Recruitment\Http\Requests\Requisition;

use Illuminate\Foundation\Http\FormRequest;

class RejectRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.requisitions.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
