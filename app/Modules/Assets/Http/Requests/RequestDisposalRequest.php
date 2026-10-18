<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dispose', $this->route('asset'));
    }

    public function rules(): array
    {
        return [
            'disposal_method' => 'required|string|in:sold,scrapped,donated,destroyed,traded,written_off',
            'proceeds'        => 'nullable|numeric|min:0',
            'buyer_info'      => 'nullable|string|max:1000',
            'reason'          => 'required|string|min:20|max:2000',
            'disposal_date'   => 'nullable|date|after_or_equal:today',
        ];
    }
}
