<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicyRule extends Model
{
    protected $fillable = [
        'code', 'name', 'category', 'threshold', 'threshold_unit',
        'period_days', 'severity', 'action', 'auto_escalate', 'active',
    ];

    protected $casts = [
        'auto_escalate' => 'boolean',
        'active'        => 'boolean',
    ];

    public const CATEGORIES = [
        'lateness'           => 'Lateness',
        'overtime'           => 'Overtime',
        'shift'              => 'Shift Rest',
        'remote_work'        => 'Remote Work',
        'missed_clocking'    => 'Missed Clocking',
        'consecutive_hours'  => 'Consecutive Hours',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const SEVERITY_COLORS = [
        'low'      => 'info',
        'medium'   => 'warning',
        'high'     => 'error',
        'critical' => 'deep-purple',
    ];

    public const ACTIONS = [
        'warn'              => 'Issue Warning',
        'block'             => 'Block Action',
        'escalate'          => 'Auto-Escalate',
        'require_approval'  => 'Require Approval',
    ];

    public function violations(): HasMany
    {
        return $this->hasMany(AttendancePolicyViolation::class, 'policy_rule_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AttendancePolicyException::class, 'policy_rule_id');
    }

    public function getSeverityColorAttribute(): string
    {
        return self::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
