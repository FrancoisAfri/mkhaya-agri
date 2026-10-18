<?php

namespace App\Modules\Communication\ContentManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReactionRequest extends FormRequest
{
    /**
     * Authorize the request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            'content_id' => [
                'nullable',
                'exists:contents,id',
                'required_without:employee_spotlight_id',
            ],

            'employee_spotlight_id' => [
                'nullable',
                'exists:employee_spotlights,id',
                'required_without:content_id',
            ],

            'like' => [
                'nullable',
                'boolean',
            ],

            'comment' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOM MESSAGES
    |--------------------------------------------------------------------------
    */

    public function messages(): array
    {
        return [
            'content_id.required_without' =>
                'A content or employee spotlight is required.',

            'employee_spotlight_id.required_without' =>
                'A content or employee spotlight is required.',
        ];
    }
}
