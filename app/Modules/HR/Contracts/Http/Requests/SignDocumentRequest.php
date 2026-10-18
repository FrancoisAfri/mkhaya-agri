<?php

namespace App\Modules\HR\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Token in query string gates access; no session auth required
    }

    public function rules(): array
    {
        return [
            'otp' => 'required|string|digits:6',
        ];
    }
}
