<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\SupplierManagement\Models\PurchaseOrder;

class PurchaseOrderItem extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_order_items';

    protected $fillable = [
        'uuid', 'purchase_order_id', 'name', 'description', 'budget_category_id',
        'quantity', 'unit_price', 'line_total', 'received_qty', 'is_asset',
        // WHMI: the stock item this line buys and the unit it is ordered in. A line with no stock
        // item -- a service, an asset -- is received without moving any stock.
        'stock_item_id', 'uom_id',
    ];

    protected $casts = [
        'quantity'     => 'decimal:2',
        'unit_price'   => 'decimal:2',
        'line_total'   => 'decimal:2',
        'received_qty' => 'decimal:2',
        'is_asset'     => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PurchaseOrderItem $m) => $m->uuid ??= (string) Str::uuid());
        static::saving(fn (PurchaseOrderItem $m) => $m->line_total = round((float) $m->quantity * (float) $m->unit_price, 2));
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** Quantity still awaiting receipt. */
    public function outstandingQty(): float
    {
        return max(0, (float) $this->quantity - (float) $this->received_qty);
    }
}
