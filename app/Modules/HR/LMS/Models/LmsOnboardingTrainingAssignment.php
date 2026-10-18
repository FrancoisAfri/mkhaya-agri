<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsOnboardingTrainingAssignment extends Model
{
    protected $table = 'lms_onboarding_training_assignments';

    public const STATUSES = ['pending', 'in_progress', 'completed', 'failed', 'overdue'];

    protected $fillable = [
        'onboarding_plan_id', 'user_id', 'rule_id', 'course_id', 'enrollment_id',
        'rule_type', 'status', 'due_date', 'assigned_at', 'completed_at',
        'pass_score', 'understanding_score', 'blocks_completion',
        'reminder_sent_at', 'notified_hr_at',
    ];

    protected $casts = [
        'due_date'         => 'date',
        'assigned_at'      => 'datetime',
        'completed_at'     => 'datetime',
        'reminder_sent_at' => 'datetime',
        'notified_hr_at'   => 'datetime',
        'pass_score'       => 'float',
        'understanding_score' => 'float',
        'blocks_completion'=> 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LmsOnboardingTrainingRule::class, 'rule_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed'  => 'success',
            'in_progress'=> 'info',
            'overdue'    => 'error',
            'failed'     => 'error',
            default      => 'warning',
        };
    }

    public function isBlockingAndFailed(): bool
    {
        return $this->blocks_completion && in_array($this->status, ['failed', 'overdue']);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }

    public function scopeIncomplete($query)
    {
        return $query->whereNotIn('status', ['completed']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue')
            ->orWhere(fn ($q) => $q
                ->whereIn('status', ['pending', 'in_progress'])
                ->where('due_date', '<', now()->toDateString())
            );
    }
}
