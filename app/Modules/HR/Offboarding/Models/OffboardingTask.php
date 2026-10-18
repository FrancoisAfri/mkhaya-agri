<?php

namespace App\Modules\HR\Offboarding\Models;

use App\Modules\HR\Offboarding\Enums\OffboardingTaskStatus;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingTask extends Model
{
    protected $table = 'offboarding_tasks';

    protected $fillable = [
        'plan_id',
        'title',
        'description',
        'category',
        'assigned_to',
        'due_date',
        'completed_by',
        'completed_at',
        'status',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'status'       => OffboardingTaskStatus::class,
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OffboardingPlan::class, 'plan_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isComplete(): bool
    {
        return $this->status->isTerminal();
    }
}
