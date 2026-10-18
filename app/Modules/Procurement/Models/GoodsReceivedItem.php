<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceivedItem extends Model
{
    use BelongsToCompany;

    protected $table = 'goods_received_items';

    protected $fillable = [
        'goods_received_note_id', 'purchase_order_item_id',
        'received_qty', 'accepted_qty', 'rejected_qty', 'condition', 'note',
        // WHMI receiving: what was actually counted, in which unit, into which bin and lot.
        'stock_item_id', 'description', 'ordered_qty', 'damaged_qty', 'missing_qty', 'excess_qty', 'txn_uom_id',
        'base_qty', 'unit_cost', 'bin_id', 'lot_id', 'serial_numbers', 'manufactured_on', 'expires_on', 'stock_status',
        'movement_id', 'company_id',
    ];

    protected $casts = [
        'received_qty' => 'decimal:2',
        'accepted_qty' => 'decimal:2',
        'rejected_qty' => 'decimal:2',
        'damaged_qty' => 'decimal:4', 'missing_qty' => 'decimal:4', 'excess_qty' => 'decimal:4', 'base_qty' => 'decimal:4',
        'serial_numbers' => 'array', 'manufactured_on' => 'date', 'expires_on' => 'date',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    /** The same relation under the name the warehouse reads it by. */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
