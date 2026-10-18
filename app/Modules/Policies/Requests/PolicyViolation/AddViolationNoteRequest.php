<?php
namespace App\Modules\Policies\Requests\PolicyViolation;
use Illuminate\Foundation\Http\FormRequest;
class AddViolationNoteRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        return [
            'content'    => ['required','string','min:3'],
            'type'       => ['required','in:comment,evidence,resolution,escalation'],
            'attachment' => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:5120'],
        ];
    }
}
