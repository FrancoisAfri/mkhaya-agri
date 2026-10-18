<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One line of a fulfilment order, with how far through the warehouse it has got. */
class FulfilmentLine extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_order_lines';

    protected $fillable = ['company_id', 'order_id', 'line_no', 'item_id', 'qty', 'txn_uom_id', 'txn_qty', 'qty_allocated', 'qty_picked', 'qty_packed', 'qty_dispatched', 'qty_cancelled', 'qty_short', 'lot_id', 'substitute_of_line_id', 'source_line_id'];

    protected $casts = ['qty' => 'decimal:4', 'txn_qty' => 'decimal:4', 'qty_allocated' => 'decimal:4', 'qty_picked' => 'decimal:4', 'qty_packed' => 'decimal:4', 'qty_dispatched' => 'decimal:4', 'qty_cancelled' => 'decimal:4', 'qty_short' => 'decimal:4'];

    public function order(): BelongsTo { return $this->belongsTo(FulfilmentOrder::class, 'order_id'); }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function allocations(): HasMany { return $this->hasMany(Allocation::class, 'order_line_id'); }

    /** Still wanted and not yet held for it. */
    public function unallocated(): float
    {
        return round(max(0.0, (float) $this->qty - (float) $this->qty_allocated - (float) $this->qty_cancelled), 4);
    }
}
