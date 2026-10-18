<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return', $this->route('assignment'));
    }

    public function rules(): array
    {
        return [
            'returned_at'      => 'nullable|date|before_or_equal:now',
            'return_condition' => 'required|string|in:new,good,fair,poor,damaged,irreparable',
            'return_notes'     => 'nullable|string|max:2000',
            'damage_amount'    => 'nullable|numeric|min:0',
        ];
    }
}
