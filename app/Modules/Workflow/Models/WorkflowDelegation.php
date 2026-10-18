<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WorkflowDelegation extends Model
{
    use BelongsToCompany;

    protected $table = 'workflow_delegations';

    protected $guarded = ['id'];

    protected $casts = ['categories' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];

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

    public function scopeActiveAt($q, $at = null)
    {
        $at ??= now();

        return $q->where('status', 'active')->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }
}
