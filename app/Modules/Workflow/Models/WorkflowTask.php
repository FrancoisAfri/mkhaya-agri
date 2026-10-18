<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WorkflowTask extends Model
{
    use BelongsToCompany;

    public const DECISIONS = ['approved', 'rejected', 'returned', 'info_requested', 'completed', 'abstained'];
    protected $table = 'workflow_tasks';

    protected $guarded = ['id'];

    protected $casts = ['candidates' => 'array', 'data' => 'array', 'due_at' => 'datetime', 'warned_at' => 'datetime', 'breached_at' => 'datetime', 'completed_at' => 'datetime'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function nodeRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowNodeRun::class, 'node_run_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'assignee_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(WorkflowTaskAssignment::class, 'task_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
