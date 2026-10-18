<?php

namespace App\Modules\Communication\ContentManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CeoMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // change if you need permissions
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title'   => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'image'   => ['nullable', 'file'],
            'video'   => ['nullable', 'file'],
        ];
    }

    /**
     * Custom validation messages (optional)
     */
    public function messages(): array
    {
        return [
            'title.required'   => 'The title is required.',
            'message.required' => 'The message is required.',
            'image.file'       => 'The image must be a valid file.',
            'video.file'       => 'The video must be a valid file.',
        ];
    }
}
