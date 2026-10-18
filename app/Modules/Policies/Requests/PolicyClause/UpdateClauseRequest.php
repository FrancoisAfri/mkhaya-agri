<?php
namespace App\Modules\Policies\Requests\PolicyClause;
use Illuminate\Foundation\Http\FormRequest;
class UpdateClauseRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        return [
            'content'           => ['required','string'],
            'ai_interpretation' => ['nullable','string'],
            'type'              => ['required','in:obligation,prohibition,threshold,approval,general'],
            'clause_reference'  => ['nullable','string','max:100'],
            'sort_order'        => ['nullable','integer'],
        ];
    }
}
