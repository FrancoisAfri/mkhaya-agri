<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunDepreciationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('assets.depreciation');
    }

    public function rules(): array
    {
        return [
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'method'       => 'required|string|in:straight_line,reducing_balance,double_declining,sum_of_years_digits,units_of_production',
            'track'        => 'required|string|in:accounting,tax',
            'category_id'  => 'nullable|integer|exists:asset_categories,id',
        ];
    }
}
