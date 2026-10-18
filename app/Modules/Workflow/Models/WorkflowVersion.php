<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WorkflowVersion extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['draft', 'in_review', 'published', 'superseded', 'retired', 'rejected'];
    protected $table = 'workflow_versions';

    protected $guarded = ['id'];

    protected $casts = ['graph' => 'array', 'pinned_forms' => 'array', 'validation' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime', 'effective_from' => 'date'];

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

    /** @return array<string, mixed>|null */
    public function node(string $key): ?array
    {
        foreach ($this->graph['nodes'] ?? [] as $n) {
            if (($n['key'] ?? null) === $key) {
                return $n;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function outgoing(string $key): array
    {
        return array_values(array_filter($this->graph['edges'] ?? [], fn ($e) => ($e['from'] ?? null) === $key));
    }

    /** @return list<array<string, mixed>> */
    public function incoming(string $key): array
    {
        return array_values(array_filter($this->graph['edges'] ?? [], fn ($e) => ($e['to'] ?? null) === $key));
    }
}
