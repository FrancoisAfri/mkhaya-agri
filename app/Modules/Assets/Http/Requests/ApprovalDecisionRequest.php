<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovalDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('approval'));
    }

    public function rules(): array
    {
        return [
            'decision'         => 'required|string|in:approve,reject',
            'comments'         => 'nullable|string|max:2000',
            'next_approver_id' => 'nullable|integer|exists:users,id',
        ];
    }
}
