<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmTask extends FarmOwnedModel
{
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::SUBMITTED, self::APPROVED, self::REJECTED, self::CANCELLED];

    /** Tasks still in somebody's hands. */
    public const PENDING = [self::OPEN, self::IN_PROGRESS, self::REJECTED];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $table = 'farm_tasks';

    protected $casts = ['due_on' => 'date', 'requires_approval' => 'boolean', 'labour_hours' => 'decimal:2',
        'submitted_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'reviewed_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to');
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && in_array($this->status, self::PENDING, true) && $this->due_on->lt(today());
    }
}
