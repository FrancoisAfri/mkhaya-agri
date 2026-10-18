<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnLine extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_supplier_return_lines';

    protected $fillable = ['company_id', 'return_id', 'item_id', 'grn_item_id', 'bin_id', 'lot_id', 'stock_status', 'qty', 'serial_numbers', 'movement_id'];

    protected $casts = ['qty' => 'decimal:4', 'serial_numbers' => 'array'];

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function bin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'bin_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }
}
