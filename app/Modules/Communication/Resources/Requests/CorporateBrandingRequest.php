<?php

namespace App\Modules\Communication\Resources\Requests;


use Illuminate\Foundation\Http\FormRequest;

class CorporateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'company_unit_id' => [
                'nullable',
                'exists:company_units,id',
            ],

            'logo_light' => [
                $this->isMethod('post') ? 'nullable' : 'nullable',
                'file',
                'mimes:png,jpg,jpeg,svg,webp',
                'max:5120', // 5MB
            ],

            'logo_dark' => [
                'nullable',
                'file',
                'mimes:png,jpg,jpeg,svg,webp',
                'max:5120',
            ],

            'favicon' => [
                'nullable',
                'file',
                'mimes:png,ico,jpg,jpeg',
                'max:1024',
            ],

            // Colors
            'primary_color' => [
                'nullable',
                'string',
                'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            ],

            'secondary_color' => [
                'nullable',
                'string',
                'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            ],

            'accent_color' => [
                'nullable',
                'string',
                'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            ],

            'background_color' => [
                'nullable',
                'string',
                'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            ],

            'text_color' => [
                'nullable',
                'string',
                'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            ],

            // Typography
            'font_family' => [
                'nullable',
                'string',
                'max:255',
            ],

            'font_url' => [
                'nullable',
                'url',
            ],

            // Status
            'is_active' => [
                'nullable',
                'boolean',
            ],

            // Extra flexible data
            'meta_data' => [
                'nullable',
                'array',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'primary_color.regex' => 'Primary color must be a valid HEX color.',
            'secondary_color.regex' => 'Secondary color must be a valid HEX color.',
            'accent_color.regex' => 'Accent color must be a valid HEX color.',
            'background_color.regex' => 'Background color must be a valid HEX color.',
            'text_color.regex' => 'Text color must be a valid HEX color.',
        ];
    }
}
