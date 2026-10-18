<?php
namespace App\Modules\Policies\Requests\PolicyRule;
use Illuminate\Foundation\Http\FormRequest;
class NaturalLanguageRuleRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        return [
            'input'     => ['required','string','min:10','max:500'],
            'policy_uuid' => ['required','uuid','exists:policy,uuid'],
            'clause_id' => ['nullable','integer','exists:policy_clauses,id'],
        ];
    }
}
