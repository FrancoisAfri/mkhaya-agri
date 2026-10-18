<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item on an inter-factory transfer, and how much of it left and arrived. */
class TransferLine extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_transfer_lines';

    protected $fillable = ['company_id', 'transfer_id', 'stock_item_id', 'lot_id', 'qty_requested', 'qty_shipped', 'qty_received', 'shipment_movement_id'];

    protected $casts = ['qty_requested' => 'float', 'qty_shipped' => 'float', 'qty_received' => 'float'];

    public function stockItem(): BelongsTo { return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')->withoutGlobalScopes(); }
}
