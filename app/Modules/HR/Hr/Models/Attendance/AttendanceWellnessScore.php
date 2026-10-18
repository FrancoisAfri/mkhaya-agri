<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWellnessScore extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end',
        'wellness_score', 'burnout_risk', 'fatigue_level',
        'total_overtime_hours', 'consecutive_days_worked', 'rest_deficit_hours',
        'late_arrivals_count', 'absenteeism_rate', 'weekend_work_count',
        'shift_changes_count', 'score_breakdown', 'ai_summary', 'calculated_at',
    ];

    protected $casts = [
        'period_start'   => 'date',
        'period_end'     => 'date',
        'score_breakdown'=> 'array',
        'calculated_at'  => 'datetime',
    ];

    const RISK_LEVELS  = ['low', 'medium', 'high', 'critical'];
    const RISK_COLORS  = ['low' => 'success', 'medium' => 'warning', 'high' => 'error', 'critical' => 'deep-purple'];
    const FATIGUE_LEVELS = ['low', 'medium', 'high'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function getRiskColorAttribute(): string
    {
        return self::RISK_COLORS[$this->burnout_risk] ?? 'grey';
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('burnout_risk', ['high', 'critical']);
    }

    public function scopeLatest30Days($query)
    {
        return $query->where('period_end', '>=', now()->subDays(30)->toDateString());
    }
}
