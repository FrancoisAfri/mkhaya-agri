<?php

namespace App\Modules\Policies\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'document' => 'nullable|file|mimes:pdf,doc,docx|max:10240', // 10MB
            'category_id' => 'nullable|exists:policy_category,id',
            'owner_id' => 'nullable|exists:users,id',
            'department_id' => 'nullable|integer',
            'status' => 'nullable|in:draft,active,under_review,archived',
            'effective_date' => 'nullable|date',
            'review_date' => 'nullable|date|after:effective_date',
            'requires_attestation' => 'boolean',
            'tags' => 'nullable|array',
            'refresh_month' => 'nullable|integer|min:1|max:12',
            'slug' => 'nullable|string|unique:policy,slug,NULL,id,deleted_at,NULL',
        ];
    }
}
