<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryRiskScore extends Model
{
    protected $table = 'disciplinary_risk_scores';

    protected $fillable = [
        'employee_id', 'risk_score', 'risk_level', 'risk_factors',
        'total_cases', 'open_cases', 'closed_cases',
        'repeat_offense_count', 'gross_misconduct_count',
        'serious_misconduct_count', 'minor_misconduct_count',
        'is_repeat_offender', 'last_case_date', 'analyzed_at', 'analyzed_by',
    ];

    protected $casts = [
        'risk_score'             => 'float',
        'risk_factors'           => 'array',
        'is_repeat_offender'     => 'boolean',
        'last_case_date'         => 'date',
        'analyzed_at'            => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function riskColor(): string
    {
        return match ($this->risk_level) {
            'critical' => 'error',
            'high'     => 'deep-orange',
            'medium'   => 'warning',
            default    => 'success',
        };
    }
}
