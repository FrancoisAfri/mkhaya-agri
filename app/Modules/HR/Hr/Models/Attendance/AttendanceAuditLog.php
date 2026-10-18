<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class AttendanceAuditLog extends Model
{
    protected $fillable = [
        'action', 'auditable_type', 'auditable_id',
        'employee_id', 'performed_by', 'performed_by_name',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'notes',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    const ACTIONS = [
        'clock_in', 'clock_out', 'manual_clock', 'clock_edit', 'clock_override',
        'overtime_approved', 'leave_applied', 'leave_approved',
        'roster_created', 'roster_published', 'roster_assignment_changed',
        'policy_violation_detected', 'policy_violation_resolved', 'policy_violation_escalated',
        'discipline_case_opened', 'discipline_case_resolved',
        'settings_changed', 'record_deleted',
    ];

    const ACTION_COLORS = [
        'clock_in'                   => 'success',
        'clock_out'                  => 'info',
        'manual_clock'               => 'warning',
        'clock_edit'                 => 'warning',
        'clock_override'             => 'error',
        'overtime_approved'          => 'purple',
        'policy_violation_detected'  => 'error',
        'discipline_case_opened'     => 'error',
        'settings_changed'           => 'secondary',
    ];

    public function getActionColorAttribute(): string
    {
        return self::ACTION_COLORS[$this->action] ?? 'default';
    }

    public static function record(
        string $action,
        ?int $employeeId = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $notes = null,
        ?string $auditableType = null,
        ?int $auditableId = null,
    ): self {
        return self::create([
            'action'            => $action,
            'auditable_type'    => $auditableType,
            'auditable_id'      => $auditableId,
            'employee_id'       => $employeeId,
            'performed_by'      => auth()->id(),
            'performed_by_name' => auth()->user()?->name,
            'old_values'        => $oldValues ?: null,
            'new_values'        => $newValues ?: null,
            'ip_address'        => request()->ip(),
            'user_agent'        => request()->userAgent(),
            'notes'             => $notes,
        ]);
    }

    public function scopeForAction($q, string $action) { return $q->where('action', $action); }
    public function scopeForEmployee($q, int $id) { return $q->where('employee_id', $id); }
    public function scopeRecent($q, int $days = 30) { return $q->where('created_at', '>=', now()->subDays($days)); }
}
