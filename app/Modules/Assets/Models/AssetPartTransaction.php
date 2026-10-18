<?php

namespace App\Modules\Assets\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single stock movement against an AssetPart.
 *
 * type: receipt | issue | adjustment.
 * `quantity` is the movement magnitude (positive); direction is implied by type.
 * `balance_after` snapshots quantity_on_hand right after the movement.
 * `work_order_id` is an optional, loosely-coupled reference (no FK/import).
 */
class AssetPartTransaction extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_part_transactions';

    protected $fillable = [
        'uuid',
        'company_id',
        'part_id',
        'type',
        'quantity',
        'balance_after',
        'unit_cost',
        'work_order_id',
        'reference',
        'performed_by',
        'notes',
        'occurred_at',
    ];

    protected $casts = [
        'quantity'      => 'decimal:2',
        'balance_after' => 'decimal:2',
        'unit_cost'     => 'decimal:2',
        'occurred_at'   => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AssetPart::class, 'part_id');
    }
}
