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

/** Stock held for one order line, at a planned bin and lot (WMS-061). */
class Allocation extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_allocations';

    protected $fillable = ['company_id', 'order_line_id', 'reservation_id', 'bin_id', 'lot_id', 'qty', 'qty_picked', 'qty_dispatched', 'status', 'release_reason', 'override_reason', 'override_by'];

    protected $casts = ['qty' => 'decimal:4', 'qty_picked' => 'decimal:4', 'qty_dispatched' => 'decimal:4'];

    public function line(): BelongsTo { return $this->belongsTo(FulfilmentLine::class, 'order_line_id'); }

    public function bin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'bin_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }
}
