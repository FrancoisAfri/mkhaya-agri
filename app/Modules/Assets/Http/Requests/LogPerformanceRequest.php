<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LogPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operating_hours_delta' => 'nullable|numeric|min:0',
            'odometer_delta'        => 'nullable|numeric|min:0',
            'utilization_rate'      => 'nullable|numeric|between:0,100',
            'downtime_minutes'      => 'nullable|integer|min:0',
            'metrics'               => 'nullable|array',
            'logged_at'             => 'nullable|date',
        ];
    }
}
