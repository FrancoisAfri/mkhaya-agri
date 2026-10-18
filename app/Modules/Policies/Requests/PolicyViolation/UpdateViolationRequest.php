<?php
namespace App\Modules\Policies\Requests\PolicyViolation;
use Illuminate\Foundation\Http\FormRequest;
class UpdateViolationRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        return [
            'status'          => ['required','in:open,under_investigation,resolved,dismissed'],
            'investigator_id' => ['nullable','integer','exists:users,id'],
        ];
    }
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var \App\Modules\Policies\Models\PolicyViolation|null $violation */
            $violation = $this->route('violation');
            if (!$violation) {
                return;
            }
            $current = $violation->status;
            $next    = $this->input('status');

            // Resolved and dismissed are terminal states
            if (in_array($current, ['resolved', 'dismissed'], true) && $current !== $next) {
                $validator->errors()->add('status', "Cannot transition a '{$current}' violation to '{$next}'.");
            }
        });
    }
}
