<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WorkflowDefinition extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['draft', 'in_review', 'published', 'suspended', 'retired'];
    protected $table = 'workflow_definitions';

    protected $guarded = ['id'];

    protected $casts = ['tags' => 'array', 'departments' => 'array', 'trigger_config' => 'array', 'start_roles' => 'array', 'restricted_user_ids' => 'array', 'is_confidential' => 'boolean'];

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

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'definition_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_version_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'definition_id');
    }

    /** The version being edited: the newest draft, in review or rejected one. */
    public function workingVersion(): ?WorkflowVersion
    {
        return $this->versions()->whereIn('status', ['draft', 'in_review', 'rejected'])->orderByDesc('version')->first();
    }
}
