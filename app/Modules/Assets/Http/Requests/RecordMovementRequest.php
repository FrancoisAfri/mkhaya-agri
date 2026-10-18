<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_location_id' => 'required|integer|exists:asset_locations,id',
            'reason'         => 'nullable|string|max:1000',
            'gps_lat'        => 'nullable|numeric|between:-90,90',
            'gps_lng'        => 'nullable|numeric|between:-180,180',
            'moved_at'       => 'nullable|date',
        ];
    }
}
