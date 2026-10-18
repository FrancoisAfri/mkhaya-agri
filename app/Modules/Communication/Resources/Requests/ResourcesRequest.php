<?php

namespace App\Modules\Communication\Resources\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'company_unit_id' => [
                'nullable',
                'integer',
                'exists:company_units,id',
            ],

            'type' => [
                'required',
                'integer',
                Rule::in([1, 2, 3, 4, 5, 6, 7, 8]),
            ],

            'file' => [
              'nullable',
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx',
                'max:10240',
            ],

            'meta_data' => [
                'nullable',
                'array',
            ],

            'internal_only' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'published_at' => [
                'nullable',
                'date',
            ],

            'expired_at' => [
                'nullable',
                'date',
                'after_or_equal:published_at',
            ],
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'is_active'     => $this->boolean('is_active'),
            'internal_only' => $this->boolean('internal_only'),
        ]);
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Invalid resource type selected.',
            'file.required' => 'A file is required when creating a resource.',
            'file.mimes' => 'File must be PDF, Word, Excel or PowerPoint.',
            'company_unit_id.exists' => 'Selected company unit does not exist.',
            'expired_at.after_or_equal' => 'Expiration date must be after or equal to publish date.',
        ];
    }
}
