<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveExitClearanceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resolveItem', $this->route('exitClearance'));
    }

    public function rules(): array
    {
        return [
            'resolution'       => 'required|string|in:returned,missing,damaged,liability',
            'notes'            => 'nullable|string|max:2000',
            'liability_amount' => 'nullable|numeric|min:0|required_if:resolution,liability,damaged',
            'return_condition' => 'nullable|string|in:new,good,fair,poor,damaged',
        ];
    }
}
