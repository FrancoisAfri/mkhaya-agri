<?php

namespace App\Modules\Assets\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spare part / MRO inventory item.
 *
 * Tenant-scoped via BelongsToCompany (company_id + CompanyScope). Routes bind by
 * uuid. `location_id` / `supplier_id` loosely reference asset_locations /
 * asset_suppliers by nullable id.
 */
class AssetPart extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_parts';

    protected $fillable = [
        'uuid',
        'company_id',
        'part_number',
        'name',
        'description',
        'category',
        'unit_of_measure',
        'unit_cost',
        'quantity_on_hand',
        'reorder_level',
        'reorder_quantity',
        'location_id',
        'supplier_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'unit_cost'        => 'decimal:2',
        'quantity_on_hand' => 'decimal:2',
        'reorder_level'    => 'decimal:2',
        'reorder_quantity' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function transactions(): HasMany
    {
        return $this->hasMany(AssetPartTransaction::class, 'part_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AssetSupplier::class, 'supplier_id');
    }

    // ── Helpers / scopes ──────────────────────────────────────────────────────

    /**
     * At or below the reorder point (and a reorder point is actually configured).
     */
    public function isLowStock(): bool
    {
        return (float) $this->reorder_level > 0
            && (float) $this->quantity_on_hand <= (float) $this->reorder_level;
    }

    /** Total value of the on-hand stock for this part. */
    public function stockValue(): float
    {
        return round((float) $this->quantity_on_hand * (float) $this->unit_cost, 2);
    }

    /** Parts at/below their (configured) reorder level. */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('reorder_level', '>', 0)
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }
}
