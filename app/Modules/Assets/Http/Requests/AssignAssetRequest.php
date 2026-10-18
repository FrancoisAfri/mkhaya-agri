<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Two screens, two names for the same date.
     *
     * `Show.vue` posts `end_date`, which these rules accept; `Assignments/Create.vue` posts
     * `return_due_date`, the column's own name, which they did not — so that screen's return date
     * was dropped by `validated()` before it reached the action, and the "must be after the
     * effective date" rule never ran on it. An assignment made there simply had no return date.
     *
     * Normalised here rather than renamed in one of the screens, because both names are already in
     * use and the column is `return_due_date` while the action reads `end_date`.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('end_date') && $this->filled('return_due_date')) {
            $this->merge(['end_date' => $this->input('return_due_date')]);
        }
    }

    public function rules(): array
    {
        return [
            'assigned_to'     => 'required|integer|exists:users,id',
            'location_id'     => 'nullable|integer|exists:asset_locations,id',
            'assignment_type' => 'required|string|in:permanent,temporary,loan',
            'effective_date'  => 'required|date',
            'end_date'        => 'nullable|date|after:effective_date',
            'notes'           => 'nullable|string|max:2000',
        ];
    }

    /**
     * Errors on the date are reported against whichever name was sent, so the screen that posted
     * `return_due_date` can show the message on its own field rather than on one it has no input
     * for.
     */
    public function messages(): array
    {
        return [
            'end_date.after' => __('The return date must be after the effective date.'),
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        if ($this->filled('return_due_date') && $validator->errors()->has('end_date')) {
            foreach ($validator->errors()->get('end_date') as $message) {
                $validator->errors()->add('return_due_date', $message);
            }
        }

        parent::failedValidation($validator);
    }
}
