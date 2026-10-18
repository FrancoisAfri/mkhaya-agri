<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendancePolicyViolation extends Model
{
    protected $fillable = [
        'employee_id', 'policy_rule_id', 'period_start', 'period_end',
        'detected_value', 'threshold_value', 'status', 'blocked',
        'ai_enforcement_decision', 'escalated_at', 'resolved_at',
        'resolution_notes', 'created_by',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'escalated_at'  => 'datetime',
        'resolved_at'   => 'datetime',
        'blocked'       => 'boolean',
    ];

    public const STATUS_COLORS = [
        'open'      => 'error',
        'escalated' => 'deep-purple',
        'resolved'  => 'success',
        'excepted'  => 'info',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function policyRule(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyRule::class, 'policy_rule_id');
    }

    public function exception(): HasOne
    {
        return $this->hasOne(AttendancePolicyException::class, 'violation_id');
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'secondary';
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeEscalated($query)
    {
        return $query->where('status', 'escalated');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }
}
