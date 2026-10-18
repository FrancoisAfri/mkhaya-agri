<?php
namespace App\Modules\Policies\Requests\Policy;
use Illuminate\Foundation\Http\FormRequest;
class StorePolicyRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        return [
            'name'                  => ['required','string','max:255'],
            'description'           => ['nullable','string'],
            'category_id'           => ['required','exists:policy_category,id'],
            'department_id'         => ['nullable','integer','exists:company_units,id'],
            'owner_id'              => ['required','integer','exists:users,id'],
            'effective_date'        => ['required','date'],
            'review_date'           => ['nullable','date','after:effective_date'],
            'status'                => ['required','in:draft,active,under_review,archived'],
            'refresh_month'         => ['nullable','integer','min:1','max:12'],
            'requires_attestation'  => ['boolean'],
            'tags'                  => ['nullable','array'],
            'tags.*'                => ['string','max:50'],
            'document'              => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'reviewer_ids'          => ['nullable','array'],
            'reviewer_ids.*'        => ['integer','exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'A policy document is required to extract clauses. Please upload a document.',
        ];
    }
}
