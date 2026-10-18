<?php

namespace App\Modules\HR\Offers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('offers.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'position_title'  => 'required|string|max:255',
            'department'      => 'nullable|string|max:255',
            'start_date'      => 'required|date|after:today',
            'salary_amount'   => 'required|numeric|min:0',
            'salary_currency' => 'sometimes|string|size:3',
            'contract_type'   => 'sometimes|string|in:permanent,fixed_term,contract,part_time',
            'notes'           => 'nullable|string|max:5000',
        ];
    }
}
