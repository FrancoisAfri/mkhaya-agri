<?php

namespace App\Modules\Dashboard\Requests;
use Illuminate\Foundation\Http\FormRequest;

class QuickAccessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ribbon_id' => ['required', 'integer', 'exists:security_module_ribbons,id'],
        ];
    }

    /**
     * Custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'ribbon_id.required' => 'The ribbon is required.',
            'ribbon_id.integer'  => 'The ribbon must be a valid integer.',
            'ribbon_id.exists'   => 'The selected ribbon does not exist.',
        ];
    }

     protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->boolean('active'),
        ]);
    }
}


