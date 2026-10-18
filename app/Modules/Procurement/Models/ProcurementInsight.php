<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProcurementInsight extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_insights';

    protected $fillable = [
        'uuid', 'type', 'category', 'severity', 'score', 'title', 'description',
        'entity_type', 'entity_id', 'amount_impact', 'signature', 'status',
        'ai_generated', 'data', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'score'         => 'decimal:2',
        'amount_impact' => 'decimal:2',
        'ai_generated'  => 'boolean',
        'reviewed_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementInsight $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function evidence(): array
    {
        return json_decode((string) $this->data, true) ?: [];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['type'] ?? null, fn ($x, $v) => $x->where('type', $v))
            ->when($f['severity'] ?? null, fn ($x, $v) => $x->where('severity', $v))
            ->when($f['category'] ?? null, fn ($x, $v) => $x->where('category', $v))
            ->when($f['status'] ?? null, fn ($x, $v) => $x->where('status', $v));
    }
}
