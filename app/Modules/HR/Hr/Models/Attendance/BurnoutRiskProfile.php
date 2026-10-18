<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BurnoutRiskProfile extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end',
        'overall_risk', 'risk_color',
        'overtime_overload_score', 'consecutive_work_score',
        'absence_pattern_score', 'late_arrival_score', 'shift_instability_score',
        'risk_factors', 'recommendations', 'ai_narrative', 'assessed_at',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'risk_factors'    => 'array',
        'recommendations' => 'array',
        'assessed_at'     => 'datetime',
    ];

    const RISK_COLORS = ['low' => 'success', 'medium' => 'warning', 'high' => 'error', 'critical' => 'deep-purple'];

    public static function colorFromRisk(string $risk): string
    {
        return self::RISK_COLORS[$risk] ?? 'grey';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function getCompositeScoreAttribute(): int
    {
        return (int) round(
            ($this->overtime_overload_score + $this->consecutive_work_score +
             $this->absence_pattern_score   + $this->late_arrival_score +
             $this->shift_instability_score) / 5
        );
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('overall_risk', ['high', 'critical']);
    }
}
