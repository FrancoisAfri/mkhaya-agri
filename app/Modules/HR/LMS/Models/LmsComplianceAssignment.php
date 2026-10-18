<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LmsComplianceAssignment extends Model
{
    protected $table = 'lms_compliance_assignments';

    public const STATUSES = ['pending', 'in_progress', 'completed', 'overdue', 'waived'];

    protected $fillable = [
        'rule_id', 'user_id', 'course_id', 'enrollment_id',
        'assigned_at', 'due_date', 'completed_at', 'status',
        'waived_by', 'waived_reason', 'waived_at',
        'compliance_period_start', 'compliance_period_end',
    ];

    protected $casts = [
        'assigned_at'             => 'date',
        'due_date'                => 'date',
        'completed_at'            => 'datetime',
        'waived_at'               => 'datetime',
        'compliance_period_start' => 'date',
        'compliance_period_end'   => 'date',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LmsComplianceRule::class, 'rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(LmsTrainingReminder::class, 'assignment_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(LmsComplianceEscalation::class, 'assignment_id');
    }

    public function daysOverdue(): int
    {
        if (!$this->due_date || in_array($this->status, ['completed', 'waived'])) return 0;
        return max(0, now()->startOfDay()->diffInDays($this->due_date, false) * -1);
    }

    public function daysUntilDue(): int
    {
        if (!$this->due_date) return 0;
        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue')
            ->orWhere(fn ($q) => $q->whereIn('status', ['pending', 'in_progress'])->where('due_date', '<', now()));
    }
}
