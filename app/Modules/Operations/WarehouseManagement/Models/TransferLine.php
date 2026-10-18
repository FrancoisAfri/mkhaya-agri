<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferLine extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_transfer_lines';

    protected $fillable = ['company_id', 'transfer_id', 'item_id', 'lot_id', 'from_bin_id', 'to_bin_id', 'qty', 'picked_qty',
        'dispatched_qty', 'received_qty', 'variance_qty', 'variance_note'];

    protected $casts = ['qty' => 'decimal:4', 'picked_qty' => 'decimal:4', 'dispatched_qty' => 'decimal:4', 'received_qty' => 'decimal:4', 'variance_qty' => 'decimal:4'];

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function fromBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'from_bin_id'); }

    public function toBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'to_bin_id'); }
}
