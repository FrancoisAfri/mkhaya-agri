<?php
namespace App\Modules\Policies\Requests\PolicyClause;
use Illuminate\Foundation\Http\FormRequest;
class ApproveClauseRequest extends FormRequest {
    public function authorize(): bool
    {
        // Only managers and above can approve clauses
        return $this->user()?->hasAnyRole(['manager', 'senior manager', 'executive', 'administrator']) ?? false;
    }
    public function rules(): array {
        return [
            'clause_ids'   => ['required','array','min:1'],
            'clause_ids.*' => ['integer','exists:policy_clauses,id'],
        ];
    }
}
