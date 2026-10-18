<?php
namespace App\Modules\Policies\Requests\PolicyRule;
use Illuminate\Foundation\Http\FormRequest;
class StoreRuleRequest extends FormRequest {
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('modify') ?? false;
    }
    public function rules(): array {
        return [
            'name'                         => ['required','string','max:255'],
            'description'                  => ['nullable','string'],
            'clause_id'                    => ['nullable','integer','exists:policy_clauses,id'],
            'natural_language_input'       => ['nullable','string'],
            'severity'                     => ['required','in:low,medium,high,critical'],
            'action_on_breach'             => ['required','in:block,warn,escalate,log_only'],
            'requires_exception_approval'  => ['boolean'],
            'active'                       => ['boolean'],
            'conditions'                   => ['required','array','min:1'],
            'conditions.*.type'            => ['required','in:numeric,role,date,status,department,custom'],
            'conditions.*.field'           => ['required','string'],
            'conditions.*.operator'        => ['required','in:eq,neq,gt,gte,lt,lte,in,not_in,between,contains'],
            'conditions.*.value'           => ['required'],
            'conditions.*.value_unit'      => ['nullable','string'],
            'conditions.*.logical_operator'=> ['in:AND,OR'],
            'actions'                      => ['required','array','min:1'],
            'actions.*.type'               => ['required','in:block,allow_with_warning,require_approval,send_notification,escalate_to_role,log_violation,trigger_workflow'],
            'actions.*.message_template'   => ['nullable','string'],
            'actions.*.config'             => ['nullable','array'],
            'event_maps'                   => ['nullable','array'],
            'event_maps.*.module'          => ['required_with:event_maps','string'],
            'event_maps.*.action_pattern'  => ['required_with:event_maps','string'],
        ];
    }
}
