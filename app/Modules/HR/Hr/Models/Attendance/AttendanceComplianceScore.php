<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceComplianceScore extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end',
        'total_score', 'lateness_score', 'overtime_score',
        'attendance_score', 'adherence_score',
        'violations_count', 'exceptions_count',
        'ai_summary', 'computed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'computed_at'  => 'datetime',
    ];

    public const RISK_COLORS = [
        'excellent'  => 'success',
        'good'       => 'info',
        'fair'       => 'warning',
        'poor'       => 'error',
        'critical'   => 'deep-purple',
    ];

    public static function colorFromScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'success',
            $score >= 75 => 'info',
            $score >= 60 => 'warning',
            $score >= 40 => 'error',
            default      => 'deep-purple',
        };
    }

    public static function labelFromScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 75 => 'Good',
            $score >= 60 => 'Fair',
            $score >= 40 => 'Poor',
            default      => 'Critical',
        };
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HrPerson::class, 'employee_id');
    }

    public function scopeLowScorers($query, int $threshold = 60)
    {
        return $query->where('total_score', '<', $threshold);
    }
}
