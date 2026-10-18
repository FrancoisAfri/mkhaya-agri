<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceAnomaly extends Model
{
    protected $table = 'attendance_anomalies';

    protected $fillable = [
        'employee_id', 'anomaly_type', 'severity', 'detected_at',
        'period_start', 'period_end', 'description', 'evidence',
        'status', 'acknowledged_by', 'acknowledged_at',
        'resolution_notes', 'ai_explanation',
    ];

    protected $casts = [
        'detected_at'     => 'datetime',
        'acknowledged_at' => 'datetime',
        'period_start'    => 'date',
        'period_end'      => 'date',
        'evidence'        => 'array',
    ];

    public const TYPES = [
        'repeated_lateness', 'unusual_absenteeism', 'excessive_overtime',
        'manipulation', 'buddy_clocking', 'missing_clockout',
        'suspicious_pattern', 'shift_abuse', 'unauthorised_absence',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const STATUSES   = ['open', 'acknowledged', 'resolved', 'false_positive'];

    public const SEVERITY_COLORS = [
        'low'      => 'info',
        'medium'   => 'warning',
        'high'     => 'error',
        'critical' => 'deep-purple',
    ];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('anomaly_type', $type);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }
}
