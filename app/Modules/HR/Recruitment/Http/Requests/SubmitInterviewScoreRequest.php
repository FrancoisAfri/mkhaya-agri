<?php

namespace App\Modules\HR\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitInterviewScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('recruitment.interviews.score') ?? false;
    }

    public function rules(): array
    {
        return [
            'technical_score'    => 'nullable|numeric|min:0|max:100',
            'communication_score'=> 'nullable|numeric|min:0|max:100',
            'culture_fit_score'  => 'nullable|numeric|min:0|max:100',
            'overall_score'      => 'required|numeric|min:0|max:100',
            'recommendation'     => 'required|in:strong_yes,yes,maybe,no,strong_no',
            'comments'           => 'nullable|string|max:5000',
        ];
    }
}
