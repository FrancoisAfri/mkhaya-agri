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

/** Pick this much of this item, lot, from this bin (WMS-063). */
class PickTask extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_pick_tasks';

    protected $fillable = ['uuid', 'company_id', 'wave_id', 'order_id', 'order_line_id', 'allocation_id', 'item_id', 'lot_id', 'bin_id', 'to_bin_id', 'qty', 'picked_qty', 'short_qty', 'sequence', 'zone', 'cluster_slot', 'status', 'scan_errors', 'picked_by', 'started_at', 'completed_at', 'note'];

    protected $casts = ['qty' => 'decimal:4', 'picked_qty' => 'decimal:4', 'short_qty' => 'decimal:4', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo { return $this->belongsTo(FulfilmentOrder::class, 'order_id'); }

    public function line(): BelongsTo { return $this->belongsTo(FulfilmentLine::class, 'order_line_id'); }

    public function allocation(): BelongsTo { return $this->belongsTo(Allocation::class, 'allocation_id'); }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function bin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'bin_id'); }

    public function wave(): BelongsTo { return $this->belongsTo(PickWave::class, 'wave_id'); }
}
