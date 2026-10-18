<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Keep a pick face (or a whole warehouse) between a minimum and a maximum (WMS-052). */
class ReplenishmentRule extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_replenishment_rules';

    protected $fillable = ['uuid', 'company_id', 'warehouse_id', 'item_id', 'pick_bin_id', 'source', 'source_warehouse_id',
        'min_qty', 'max_qty', 'multiple', 'is_active'];

    protected $casts = ['min_qty' => 'decimal:4', 'max_qty' => 'decimal:4', 'multiple' => 'decimal:4', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function pickBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'pick_bin_id'); }

    public function sourceWarehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'source_warehouse_id'); }
}
