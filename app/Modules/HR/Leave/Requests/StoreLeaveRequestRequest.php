<?php

namespace App\Modules\HR\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id'   => ['required', 'integer', 'exists:hr_people,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date'    => ['required', 'date', 'after_or_equal:today'],
            'end_date'      => ['required', 'date', 'after_or_equal:start_date'],
            'request_mode'  => ['required', 'in:daily,hourly'],
            'start_time'    => ['required_if:request_mode,hourly', 'nullable', 'date_format:H:i'],
            'end_time'      => ['required_if:request_mode,hourly', 'nullable', 'date_format:H:i', 'after:start_time'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'is_overtime'   => ['boolean'],
            'is_bulk'       => ['boolean'],
            // Inline sick-note / supporting document uploaded with the application.
            'document'      => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'document_type' => ['nullable', 'in:medical_certificate,supporting_doc,birth_certificate,other'],
        ];
    }

    /**
     * Enforce the SA sick-note rule: a medical certificate must accompany sick leave that falls
     * on a Monday/Friday or spans two or more consecutive days (and the 8-week threshold). If the
     * certificate is required but not attached, block the application.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->hasFile('document')) {
                return;
            }
            $leaveType = \App\Modules\HR\Leave\Models\LeaveType::find($this->input('leave_type_id'));
            if (! $leaveType || $leaveType->statutory_type !== 'sick') {
                return;
            }
            $employee = \App\Modules\HR\Hr\Models\HRPerson::find($this->input('employee_id'));
            if (! $employee || ! $this->input('start_date') || ! $this->input('end_date')) {
                return;
            }
            $check = app(\App\Modules\HR\Leave\Services\SickLeaveCertificateRule::class)
                ->isCertificateRequired($employee, (string) $this->input('start_date'), (string) $this->input('end_date'));
            if ($check['required']) {
                $validator->errors()->add('document', 'A medical certificate is required. ' . implode(' ', $check['reasons']));
            }
        });
    }

    public function messages(): array
    {
        return [
            'employee_id.exists'   => 'The selected employee does not exist.',
            'leave_type_id.exists' => 'The selected leave type does not exist.',
            'start_date.after_or_equal' => 'Start date cannot be in the past.',
            'end_date.after_or_equal'   => 'End date must be on or after the start date.',
            'end_time.after'            => 'End time must be after start time.',
        ];
    }
}
