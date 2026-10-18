<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class AttendanceDepartmentWellnessScore extends Model
{
    protected $fillable = [
        'department', 'period_start', 'period_end',
        'wellness_score', 'risk_level', 'risk_color',
        'headcount', 'high_risk_count', 'open_alerts_count',
        'avg_absenteeism_rate', 'avg_overtime_hours',
        'top_issues', 'ai_summary', 'calculated_at',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'top_issues'    => 'array',
        'calculated_at' => 'datetime',
    ];

    const RISK_COLORS = ['low' => 'success', 'medium' => 'warning', 'high' => 'error', 'critical' => 'deep-purple'];

    public static function colorFromRisk(string $risk): string
    {
        return self::RISK_COLORS[$risk] ?? 'grey';
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }
}
