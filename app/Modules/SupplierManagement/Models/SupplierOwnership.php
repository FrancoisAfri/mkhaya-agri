<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One ownership edge: this holder owns this much of this entity, as at this date, according to this
 * source.
 *
 * Dated rather than current-only. "Who owned this supplier when the contract was awarded" is a
 * question that gets asked after something has gone wrong, and a table holding only today's position
 * cannot answer it — so an ended stake is closed off with `ended_at` rather than deleted.
 */
class SupplierOwnership extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'supplier_ownerships';

    protected $fillable = [
        'uuid', 'supplier_id', 'holder_kind', 'holder_id', 'subject_entity_id',
        'percentage', 'ownership_type', 'as_at', 'ended_at', 'source', 'notes', 'created_by',
    ];

    protected $casts = [
        'percentage' => 'decimal:3',
        'as_at' => 'date',
        'ended_at' => 'date',
    ];

    public const KIND_PERSON = 'person';

    public const KIND_ENTITY = 'entity';

    public const TYPES = ['shares', 'member', 'beneficial', 'control', 'trustee'];

    protected static function booted(): void
    {
        static::creating(fn (self $edge) => $edge->uuid ??= (string) Str::uuid());
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(SupplierEntity::class, 'subject_entity_id');
    }

    /** The holder, whichever kind it is. */
    public function holder(): ?Model
    {
        return $this->holder_kind === self::KIND_PERSON
            ? SupplierPerson::find($this->holder_id)
            : SupplierEntity::find($this->holder_id);
    }

    /** Stakes that have not been closed off. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', now()->toDateString()));
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function isPersonHolder(): bool
    {
        return $this->holder_kind === self::KIND_PERSON;
    }
}
