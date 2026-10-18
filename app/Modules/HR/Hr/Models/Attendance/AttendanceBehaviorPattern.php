<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceBehaviorPattern extends Model
{
    protected $table = 'attendance_behavior_patterns';

    protected $fillable = [
        'employee_id', 'pattern_type', 'period_type',
        'period_start', 'period_end', 'data', 'insight', 'confidence',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'data'         => 'array',
        'confidence'   => 'float',
    ];

    public const TYPES       = ['late_days', 'absence_days', 'overtime_days', 'early_departure_days', 'work_type_preference', 'buddy_pair', 'shift_deviation'];
    public const PERIOD_TYPES = ['weekly', 'monthly', 'quarterly'];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('pattern_type', $type);
    }
}
