<?php

namespace App\Modules\Policies\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|boolean',
        ];
    }

}
