<?php
namespace App\Modules\Policies\Requests\PolicyException;
use Illuminate\Foundation\Http\FormRequest;
class DecideExceptionRequest extends FormRequest {
    public function authorize(): bool
    {
        // Only managers and above can approve/reject exception requests
        return $this->user()?->hasAnyRole(['manager', 'senior manager', 'executive', 'administrator']) ?? false;
    }
    public function rules(): array {
        return [
            'decision'       => ['required','in:approved,rejected'],
            'decision_notes' => ['nullable','string'],
        ];
    }
}
