<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Services\FieldComparator;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A company node in a supplier's ownership chain.
 *
 * Most of these are not suppliers — a holding company you will never buy from is still part of the
 * answer to who owns the one you do. The supplier itself gets a node too (`is_root`), so the graph
 * has one shape throughout rather than a special case at the top.
 *
 * `normalised_name` is maintained on every write because entity matching is done on it: "ABC
 * Holdings (Pty) Ltd" and "ABC HOLDINGS PROPRIETARY LIMITED" are the same company, and a graph that
 * treats them as two nodes hides the very relationship it was built to show.
 */
class SupplierEntity extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'supplier_entities';

    protected $fillable = [
        'uuid', 'supplier_id', 'name', 'normalised_name', 'registration_number', 'country',
        'entity_type', 'supplier_ref_id', 'is_root', 'source', 'notes',
    ];

    protected $casts = [
        'is_root' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $entity) => $entity->uuid ??= (string) Str::uuid());

        static::saving(function (self $entity) {
            $entity->normalised_name = $entity->name
                ? app(FieldComparator::class)->normaliseEntityName($entity->name)
                : null;
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** When this node is itself a supplier on this tenant. */
    public function supplierRef(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_ref_id');
    }

    /** Stakes held IN this entity. */
    public function holdings(): HasMany
    {
        return $this->hasMany(SupplierOwnership::class, 'subject_entity_id');
    }

    /**
     * The root node for a supplier, created on demand.
     *
     * Every graph needs somewhere to start, and making the caller remember to create it is how you
     * end up with ownership edges pointing at nothing.
     */
    public static function rootFor(Supplier $supplier): self
    {
        return static::query()->firstOrCreate(
            ['supplier_id' => $supplier->id, 'is_root' => true],
            [
                'name' => $supplier->company_name,
                'registration_number' => $supplier->company_registration_number,
                'supplier_ref_id' => $supplier->id,
                'entity_type' => $supplier->business_entity_type,
                'source' => 'supplier',
            ]
        );
    }
}
