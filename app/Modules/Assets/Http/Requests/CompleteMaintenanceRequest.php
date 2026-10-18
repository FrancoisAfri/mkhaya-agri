<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complete', $this->route('schedule'));
    }

    public function rules(): array
    {
        return [
            'completed_at'    => 'required|date|before_or_equal:now',
            'actual_cost'     => 'nullable|numeric|min:0',
            'labor_hours'     => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:2000',
            'parts_replaced'  => 'nullable|array',
            'parts_replaced.*'=> 'string|max:255',
            'next_due_date'   => 'nullable|date|after:today',
        ];
    }
}
