<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WorkflowForm extends Model
{
    use BelongsToCompany;

    protected $table = 'workflow_forms';

    protected $guarded = ['id'];

    protected $casts = [];

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
        return $this->hasMany(WorkflowFormVersion::class, 'form_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowFormVersion::class, 'current_version_id');
    }
}
