<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of a balance: this item, in this warehouse, bin and lot, in this status.
 *
 * The sum of an item's positions in a warehouse is that warehouse's balance -- always, because both
 * are written by InventoryService::applyBalance() in the same transaction. It is a breakdown of the
 * ledger's balance, not a second balance (MFG-008 control 7).
 */
class FinStockPosition extends Model
{
    use BelongsToCompany, BelongsToEntity;

    protected $table = 'fin_stock_positions';

    protected $fillable = ['company_id', 'entity_id', 'item_id', 'warehouse_id', 'bin_id', 'lot_id', 'status', 'qty'];

    protected $casts = ['qty' => 'decimal:4'];

    public const AVAILABLE = 'AVAILABLE';
    public const QUARANTINE = 'QUARANTINE';
    public const ON_HOLD = 'ON_HOLD';
    public const REJECTED = 'REJECTED';

    // WHMI (WMS-021). What each state counts towards is defined in one place: StockStates.
    public const DAMAGED = 'DAMAGED';
    public const INSPECTION = 'INSPECTION';
    public const RECALLED = 'RECALLED';

    public const STATUSES = [self::AVAILABLE, self::QUARANTINE, self::ON_HOLD, self::REJECTED, self::DAMAGED, self::INSPECTION, self::RECALLED];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FinWarehouse::class, 'warehouse_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(FinStockBin::class, 'bin_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(FinStockLot::class, 'lot_id');
    }
}
