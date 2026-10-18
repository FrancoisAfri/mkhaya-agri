<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryBehaviorProfile extends Model
{
    protected $table = 'disciplinary_behavior_profiles';

    protected $fillable = [
        'employee_id', 'period_start', 'period_end',
        'late_arrivals', 'unexcused_absences', 'early_departures', 'attendance_score',
        'performance_rating', 'missed_kpis', 'performance_score',
        'active_warnings', 'total_cases_ytd', 'repeated_offences', 'disciplinary_score',
        'overdue_training_items', 'compliance_score',
        'overall_risk_score', 'risk_level', 'risk_factors', 'ai_narrative',
        'is_ai_generated', 'ai_provider', 'ai_model', 'generated_by',
    ];

    protected $casts = [
        'period_start'       => 'date',
        'period_end'         => 'date',
        'risk_factors'       => 'array',
        'is_ai_generated'    => 'boolean',
        'performance_rating' => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('period_end');
    }
}
