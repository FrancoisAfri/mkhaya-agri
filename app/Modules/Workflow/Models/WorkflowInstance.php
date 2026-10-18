<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WorkflowInstance extends Model
{
    use BelongsToCompany, \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    public const STATUSES = ['running', 'completed', 'rejected', 'cancelled', 'failed', 'suspended'];

    public const OPEN = ['running', 'suspended'];
    protected $table = 'workflow_instances';

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

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

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    public function nodeRuns(): HasMany
    {
        return $this->hasMany(WorkflowNodeRun::class, 'instance_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'instance_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkflowComment::class, 'instance_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'requester_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
