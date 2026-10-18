<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:5000',
            'severity'        => 'required|string|in:low,medium,high,critical',
            'repair_cost'     => 'nullable|numeric|min:0',
            'condition_after' => 'nullable|string|in:new,good,fair,poor,damaged,irreparable',
            'occurred_at'     => 'nullable|date',
        ];
    }
}
