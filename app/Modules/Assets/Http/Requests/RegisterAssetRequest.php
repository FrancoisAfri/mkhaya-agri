<?php

namespace App\Modules\Assets\Http\Requests;

use App\Modules\Assets\Enums\SarsAllowanceSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class RegisterAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => 'required|string|max:500',
            'description'            => 'nullable|string|max:65535',
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
            'sars_allowance_section' => ['nullable', new Enum(SarsAllowanceSection::class)],
            'images'                 => 'nullable|array|max:10',
            'images.*'               => 'image|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ];
    }
}
