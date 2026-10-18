<?php

namespace App\Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'maintenance_type' => 'required|string|in:preventive,corrective,predictive,calibration,inspection',
            'due_date'         => 'required|date',
            'assigned_to'      => 'nullable|integer|exists:users,id',
            'vendor_name'      => 'nullable|string|max:255',
            'estimated_cost'   => 'nullable|numeric|min:0',
            'recurrence_days'  => 'nullable|integer|min:1',
            'notes'            => 'nullable|string|max:2000',
        ];
    }
}
