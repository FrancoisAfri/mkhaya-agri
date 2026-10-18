<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\CompanyUnits\Models\CompanyUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentBehaviorRisk extends Model
{
    protected $table = 'department_behavior_risk';

    protected $fillable = [
        'department_id', 'period_start', 'period_end',
        'total_cases', 'active_cases', 'dismissals', 'suspensions',
        'warnings_issued', 'repeat_offenders', 'avg_case_duration_days',
        'risk_score', 'risk_level', 'trend', 'top_offence_categories',
        'ai_narrative', 'is_ai_generated', 'ai_provider', 'ai_model',
    ];

    protected $casts = [
        'period_start'           => 'date',
        'period_end'             => 'date',
        'top_offence_categories' => 'array',
        'is_ai_generated'        => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(CompanyUnit::class, 'department_id');
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }
}
