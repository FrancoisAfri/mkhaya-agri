<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('assets.update');
    }

    public function rules(): array
    {
        return [
            'asset_number_prefix'       => 'required|string|max:10',
            'asset_number_format'       => 'required|string|max:50',
            'financial_year_start'      => 'required|string|max:5',
            'approval_levels'           => 'required|integer|min:1|max:5',
            'approval_threshold_l2'     => 'nullable|numeric|min:0',
            'approval_threshold_l3'     => 'nullable|numeric|min:0',
            'enable_dual_depreciation'  => 'required|boolean',
            'enable_impairment'         => 'required|boolean',
            'enable_lease_rou'          => 'required|boolean',
            'depreciation_run'          => 'required|string|in:monthly,quarterly,annual',
        ];
    }
}
