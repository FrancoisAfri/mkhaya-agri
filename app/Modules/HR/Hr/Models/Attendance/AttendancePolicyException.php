<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePolicyException extends Model
{
    protected $fillable = [
        'violation_id', 'employee_id', 'policy_rule_id',
        'reason', 'granted_by', 'approved_at', 'expires_at', 'notes',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'expires_at'  => 'date',
    ];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyViolation::class, 'violation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function policyRule(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyRule::class, 'policy_rule_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'granted_by');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
