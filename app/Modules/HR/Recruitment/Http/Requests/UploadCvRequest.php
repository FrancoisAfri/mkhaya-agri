<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.candidates.upload_cv') ?? false;
    }

    public function rules(): array
    {
        return [
            'cv' => [
                'required',
                'file',
                'max:10240', // 10 MB
                // mimetypes validates binary signature via finfo, not file extension
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'cv.mimetypes' => 'CV must be a PDF or DOCX file.',
        ];
    }
}
