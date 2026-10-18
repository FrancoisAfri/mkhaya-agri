<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    protected $table = 'attendance_settings';

    protected $fillable = [
        'work_start_time',
        'work_end_time',
        'grace_period_minutes',
        'overtime_threshold_minutes',
        'early_departure_threshold_minutes',
        'late_policy_enabled',
        'overtime_enabled',
        'auto_clock_out_enabled',
        'auto_clock_out_time',
        'require_shift_assignment',
        'allow_manual_entry',
        'manager_approval_required',
        'working_days_per_week',
        'working_hours_per_day',
        'timezone',
        'use_employee_work_hours',
        'google_maps_api_key',
        // ERS Biometric Integration
        'ers_enabled',
        'ers_token',
        'ers_api_url',
        'ers_clock_start_time',
        'ers_clock_end_time',
        // Absence notification + auto-leave
        'absent_email_enabled',
        'auto_apply_leave_enabled',
        'auto_apply_days_threshold',
        'auto_deduct_leave',
        'allow_unpaid_when_annual_done',
    ];

    protected $casts = [
        'late_policy_enabled'           => 'boolean',
        'overtime_enabled'              => 'boolean',
        'auto_clock_out_enabled'        => 'boolean',
        'require_shift_assignment'      => 'boolean',
        'allow_manual_entry'            => 'boolean',
        'manager_approval_required'     => 'boolean',
        'use_employee_work_hours'       => 'boolean',
        'ers_enabled'                   => 'boolean',
        'absent_email_enabled'          => 'boolean',
        'auto_apply_leave_enabled'      => 'boolean',
        'auto_deduct_leave'             => 'boolean',
        'allow_unpaid_when_annual_done' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'work_start_time'                   => '08:00:00',
            'work_end_time'                     => '17:00:00',
            'grace_period_minutes'              => 15,
            'overtime_threshold_minutes'        => 30,
            'early_departure_threshold_minutes' => 15,
            'working_days_per_week'             => 5,
            'working_hours_per_day'             => 8,
            'timezone'                          => 'Africa/Johannesburg',
            'ers_api_url'                       => 'https://r14.ersbio.co.za/api/data_client.php',
            'ers_clock_start_time'              => '05:00:00',
            'ers_clock_end_time'                => '20:00:00',
            'auto_apply_days_threshold'         => 3,
        ]);
    }
}
