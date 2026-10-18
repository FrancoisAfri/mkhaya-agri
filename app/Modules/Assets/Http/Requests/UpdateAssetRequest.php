<?php

namespace App\Modules\Assets\Http\Requests;

use App\Modules\Assets\Enums\SarsAllowanceSection;
use App\Modules\Assets\Models\Assets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('asset') ?? Assets::class);
    }

    public function rules(): array
    {
        return [
            'name'                   => 'sometimes|string|max:255',
            'description'            => 'nullable|string',
            'serial_number'          => 'nullable|string|max:100',
            'asset_tag'              => 'nullable|string|max:60',
            'asset_make_id'          => 'nullable|integer|exists:asset_makes,id',
            'asset_model_id'         => 'nullable|integer|exists:asset_models,id',
            'barcode'                => 'nullable|string|max:60',
            'asset_type_id'          => 'nullable|integer|exists:asset_type,id',
            'asset_category_id'      => 'nullable|integer|exists:asset_categories,id',
            'location_id'            => 'nullable|integer|exists:asset_locations,id',
            'purchase_cost'          => 'nullable|numeric|min:0',
            'purchase_date'          => 'nullable|date',
            'warranty_expiry'        => 'nullable|date|after_or_equal:purchase_date',
            'condition'              => 'nullable|string|in:new,good,fair,poor,damaged,irreparable',
            'asset_status'           => 'nullable|string|in:active,unallocated,in_store,under_maintenance,disposed,sold,missing,transferred',
            'sars_allowance_section' => ['nullable', new Enum(SarsAllowanceSection::class)],
        ];
    }
}
