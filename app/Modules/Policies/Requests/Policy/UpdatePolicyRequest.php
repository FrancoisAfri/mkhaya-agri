<?php
namespace App\Modules\Policies\Requests\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdatePolicyRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        $policyId = $this->route('policy') instanceof \App\Modules\Policies\Models\Policy
            ? $this->route('policy')->id
            : (int) $this->route('policy');

        return [
            'name'                  => ['sometimes','required','string','max:255'],
            'description'           => ['nullable','string'],
            'slug'                  => ['nullable','string','unique:policy,slug,' . $policyId],
            'category_id'           => ['sometimes','required','exists:policy_category,id'],
            'department_id'         => ['nullable','integer','exists:company_units,id'],
            'owner_id'              => ['sometimes','required','integer','exists:users,id'],
            'effective_date'        => ['sometimes','required','date'],
            'review_date'           => ['nullable','date','after:effective_date'],
            'status'                => ['sometimes','in:draft,active,under_review,archived'],
            'refresh_month'         => ['nullable','integer','min:1','max:12'],
            'requires_attestation'  => ['boolean'],
            'tags'                  => ['nullable','array'],
            'document'              => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'change_summary'        => ['nullable','string','max:1000'],
            'reviewer_ids'          => ['nullable','array'],
            'reviewer_ids.*'        => ['integer','exists:users,id'],
        ];
    }

}
