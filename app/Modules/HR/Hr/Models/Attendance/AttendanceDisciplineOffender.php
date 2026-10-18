<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDisciplineOffender extends Model
{
    protected $fillable = [
        'employee_id', 'total_violations', 'late_count', 'absent_count',
        'no_show_count', 'early_departure_count', 'prior_warnings',
        'offender_level', 'offender_color',
        'first_offence_date', 'last_offence_date',
        'period_start', 'period_end',
        'violation_breakdown', 'ai_risk_assessment', 'assessed_at',
    ];

    protected $casts = [
        'first_offence_date'  => 'date',
        'last_offence_date'   => 'date',
        'period_start'        => 'date',
        'period_end'          => 'date',
        'violation_breakdown' => 'array',
        'assessed_at'         => 'datetime',
    ];

    const LEVELS = [
        'minor'    => 'Minor',
        'moderate' => 'Moderate',
        'serious'  => 'Serious',
        'repeat'   => 'Repeat Offender',
    ];

    const LEVEL_COLORS = ['minor' => 'info', 'moderate' => 'warning', 'serious' => 'error', 'repeat' => 'deep-purple'];

    public static function colorFromLevel(string $level): string
    {
        return self::LEVEL_COLORS[$level] ?? 'grey';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function scopeSerious($query)
    {
        return $query->whereIn('offender_level', ['serious', 'repeat']);
    }
}
