<?php

namespace App\Modules\Communication\ContentManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | RULES
    |--------------------------------------------------------------------------
    */

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'summary'     => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:255'],
            'event_start' => ['nullable', 'date_format:H:i'],
            'event_end'   => ['nullable', 'date_format:H:i'],
            'type'        => ['required', 'integer', 'in:1,2,3,4'],
            'is_active'   => ['nullable', 'boolean'],
            'event_date'      => ['nullable', 'date'],
            'expired_at'      => ['nullable', 'date'],
            'meta_data'   => ['nullable', 'array'],
            'image'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp'],
            'video'       => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm'],
            'document'    => ['nullable', 'file', 'mimes:pdf,doc,docx'],
            'company_unit_id' => 'nullable|integer|exists:company_units,id',
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
            'title.required' => 'The title is required.',
            'type.required'  => 'The content type is required.',
            'type.in'        => 'Invalid content type.',
        ];
    }
}
