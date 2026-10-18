<?php

namespace App\Modules\Communication\ContentManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeSpotlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id'     => ['required', 'exists:users,id'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'month' => ['nullable', 'date'],
            'type'        => ['required', 'integer', 'in:1,2,3'],
            'image'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}
