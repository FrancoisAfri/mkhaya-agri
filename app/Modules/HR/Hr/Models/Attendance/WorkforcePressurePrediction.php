<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkforcePressurePrediction extends Model
{
    protected $fillable = [
        'prediction_type', 'scope', 'employee_id', 'department',
        'predicted_for', 'period_start', 'period_end',
        'confidence', 'risk_level', 'risk_color',
        'evidence', 'ai_explanation', 'status',
    ];

    protected $casts = [
        'predicted_for' => 'date',
        'period_start'  => 'date',
        'period_end'    => 'date',
        'evidence'      => 'array',
    ];

    const TYPES = [
        'absenteeism_spike' => 'Absenteeism Spike',
        'turnover_risk'     => 'Turnover Risk',
        'understaffing'     => 'Understaffing',
        'fatigue_outbreak'  => 'Fatigue Outbreak',
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

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical'])->where('status', 'active');
    }
}
