<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryBehaviorPattern extends Model
{
    protected $table = 'disciplinary_behavior_patterns';

    protected $fillable = [
        'employee_id', 'pattern_type', 'pattern_label', 'pattern_description',
        'severity', 'supporting_data', 'detected_at',
        'acknowledged', 'acknowledged_by', 'acknowledged_at',
    ];

    protected $casts = [
        'supporting_data'  => 'array',
        'detected_at'      => 'date',
        'acknowledged'     => 'boolean',
        'acknowledged_at'  => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function severityColor(): string
    {
        return match ($this->severity) {
            'high'   => 'error',
            'medium' => 'warning',
            default  => 'info',
        };
    }

    public static function patternIcon(string $type): string
    {
        return match ($type) {
            'repeat_offender'       => 'mdi-repeat-variant',
            'attendance_misconduct' => 'mdi-clock-alert-outline',
            'payroll_fraud_risk'    => 'mdi-currency-usd-off',
            'policy_violation'      => 'mdi-file-cancel-outline',
            'team_behavioral'       => 'mdi-account-group-outline',
            default                 => 'mdi-alert-circle-outline',
        };
    }
}
