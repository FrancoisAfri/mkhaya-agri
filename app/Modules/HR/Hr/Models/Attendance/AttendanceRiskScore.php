<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceRiskScore extends Model
{
    protected $table = 'attendance_risk_scores';

    protected $fillable = [
        'employee_id', 'score', 'risk_level', 'calculated_at',
        'period_start', 'period_end',
        'late_count', 'absent_count', 'missed_clockout_count',
        'anomaly_count', 'overtime_excessive_days', 'attendance_rate',
        'score_breakdown', 'ai_summary',
    ];

    protected $casts = [
        'calculated_at'  => 'datetime',
        'period_start'   => 'date',
        'period_end'     => 'date',
        'score'          => 'float',
        'attendance_rate' => 'float',
        'score_breakdown' => 'array',
    ];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public const RISK_COLORS = [
        'low'      => 'success',
        'medium'   => 'warning',
        'high'     => 'error',
        'critical' => 'deep-purple',
    ];

    public static function levelFromScore(float $score): string
    {
        return match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default      => 'low',
        };
    }

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }

    public function scopeLatest30Days($query)
    {
        return $query->where('calculated_at', '>=', now()->subDays(30));
    }
}
