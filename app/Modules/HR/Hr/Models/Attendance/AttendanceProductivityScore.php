<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceProductivityScore extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end',
        'overall_score', 'punctuality_score', 'attendance_rate_score',
        'overtime_balance_score', 'consistency_score',
        'attendance_rate', 'days_present', 'days_absent',
        'late_arrivals', 'early_departures', 'overtime_hours',
        'productivity_tier', 'ai_insights', 'computed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'computed_at'  => 'datetime',
    ];

    const TIERS = ['high', 'average', 'low', 'at_risk'];

    const TIER_COLORS = [
        'high'    => 'success',
        'average' => 'info',
        'low'     => 'warning',
        'at_risk' => 'error',
    ];

    public function getTierColorAttribute(): string
    {
        return self::TIER_COLORS[$this->productivity_tier] ?? 'default';
    }

    public static function tierFromScore(int $score): string
    {
        return match (true) {
            $score >= 80 => 'high',
            $score >= 60 => 'average',
            $score >= 40 => 'low',
            default      => 'at_risk',
        };
    }
    public function employee(){
        return $this->belongsTo(HRPerson::class,"employee_id");
    }

    public function scopeForEmployee($q, int $id) { return $q->where('employee_id', $id); }
    public function scopeLowPerformers($q) { return $q->whereIn('productivity_tier', ['low', 'at_risk']); }
}
