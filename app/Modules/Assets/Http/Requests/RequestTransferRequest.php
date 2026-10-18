<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer', $this->route('asset'));
    }

    public function rules(): array
    {
        return [
            'from_location_id' => 'nullable|integer|exists:asset_locations,id',
            'to_location_id'   => 'required_without:to_user_id|integer|exists:asset_locations,id',
            'to_user_id'       => 'required_without:to_location_id|integer|exists:users,id',
            'reason'           => 'required|string|min:10|max:1000',
            'transfer_date'    => 'required|date|after_or_equal:today',
        ];
    }
}
