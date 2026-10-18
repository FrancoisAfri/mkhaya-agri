<?php

namespace App\Modules\HR\Offers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOfferOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Candidate-facing — no auth required; offer token gates access
    }

    public function rules(): array
    {
        return [
            'otp' => 'required|string|digits:6',
        ];
    }
}
