<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class DepartmentEfficiencyScore extends Model
{
    protected $fillable = [
        'department', 'period_start', 'period_end',
        'efficiency_score', 'headcount',
        'avg_attendance_rate', 'avg_punctuality_rate',
        'total_overtime_hours', 'overworked_count', 'low_productivity_count',
        'efficiency_tier', 'is_overworked_dept', 'ai_summary', 'computed_at',
    ];

    protected $casts = [
        'period_start'       => 'date',
        'period_end'         => 'date',
        'is_overworked_dept' => 'boolean',
        'computed_at'        => 'datetime',
    ];

    const TIER_COLORS = [
        'high'     => 'success',
        'average'  => 'info',
        'low'      => 'warning',
        'critical' => 'error',
    ];

    public function getTierColorAttribute(): string
    {
        return self::TIER_COLORS[$this->efficiency_tier] ?? 'default';
    }

    public static function tierFromScore(int $score): string
    {
        return match (true) {
            $score >= 80 => 'high',
            $score >= 60 => 'average',
            $score >= 40 => 'low',
            default      => 'critical',
        };
    }

    public function scopeOverworked($q) { return $q->where('is_overworked_dept', true); }
    public function scopeLowEfficiency($q) { return $q->whereIn('efficiency_tier', ['low', 'critical']); }
}
