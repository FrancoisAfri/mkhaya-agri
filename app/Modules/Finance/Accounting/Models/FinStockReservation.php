<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Stock promised to something -- a production order line -- but not yet moved.
 *
 * Remaining is qty - qty_consumed and is never stored separately, so it cannot disagree with
 * itself. Reserved stock is excluded from what anybody else can issue (MFG-008 control 1) and from
 * what MRP counts as free supply (MFG-005: "must not double-count stock already allocated").
 */
class FinStockReservation extends Model
{
    use BelongsToCompany, BelongsToEntity;

    protected $table = 'fin_stock_reservations';

    protected $fillable = [
        'uuid', 'company_id', 'entity_id', 'item_id', 'warehouse_id', 'lot_id', 'qty', 'qty_consumed',
        'source_type', 'source_id', 'status', 'release_reason', 'released_at', 'created_by',
    ];

    protected $casts = ['qty' => 'decimal:4', 'qty_consumed' => 'decimal:4', 'released_at' => 'datetime'];

    public const OPEN = 'OPEN';
    public const CONSUMED = 'CONSUMED';
    public const RELEASED = 'RELEASED';

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->qty - (float) $this->qty_consumed, 4));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'item_id');
    }
}
