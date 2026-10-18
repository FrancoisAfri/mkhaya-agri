<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveApprovalLevel extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveApproval')
            ->logOnly(['leave_request_id', 'level', 'approver_id', 'status',
                       'actioned_at', 'comment', 'escalated_at', 'is_policy_override', 'override_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Approval level {$this->level} created for request #{$this->leave_request_id}",
                'updated' => match(true) {
                    $this->isDirty('status') && $this->status === 'approved' =>
                        "Request #{$this->leave_request_id} approved at level {$this->level}",
                    $this->isDirty('status') && $this->status === 'rejected' =>
                        "Request #{$this->leave_request_id} rejected at level {$this->level}",
                    $this->isDirty('escalated_at') =>
                        "Request #{$this->leave_request_id} escalated at level {$this->level}",
                    default => "Approval level {$this->level} updated for request #{$this->leave_request_id}",
                },
                'deleted' => "Approval level {$this->level} deleted for request #{$this->leave_request_id}",
                default   => "Approval level {$this->level}: {$e}",
            });
    }

    protected $table = 'leave_approval_levels';

    protected $fillable = [
        'leave_request_id', 'level', 'level_name', 'approver_id',
        'delegated_from_id', 'status', 'actioned_at', 'comment',
        'is_required', 'escalated_at', 'escalation_reason',
        'parallel_group', 'is_delegated', 'sla_due_at',
        'is_policy_override', 'override_reason',
    ];

    protected $casts = [
        'level'              => 'integer',
        'parallel_group'     => 'integer',
        'is_required'        => 'boolean',
        'is_delegated'       => 'boolean',
        'is_policy_override' => 'boolean',
        'actioned_at'        => 'datetime',
        'escalated_at'       => 'datetime',
        'sla_due_at'         => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForApprover($query, int $userId)
    {
        return $query->where('approver_id', $userId);
    }

    public static function levelName(int $level): string
    {
        return match($level) {
            1 => 'Manager',
            2 => 'Department Head',
            3 => 'HR Manager',
            4 => 'Payroll Officer',
            default => "Level {$level}",
        };
    }
}
