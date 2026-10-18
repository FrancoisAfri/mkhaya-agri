<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReplenishmentTask extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_replenishment_tasks';

    protected $fillable = ['uuid', 'company_id', 'number', 'rule_id', 'warehouse_id', 'item_id', 'lot_id', 'from_bin_id', 'to_bin_id',
        'qty', 'done_qty', 'priority', 'priority_reasons', 'status', 'transfer_id', 'completed_by', 'completed_at', 'exception_note'];

    protected $casts = ['qty' => 'decimal:4', 'done_qty' => 'decimal:4', 'priority_reasons' => 'array', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function fromBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'from_bin_id'); }

    public function toBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'to_bin_id'); }

    public function rule(): BelongsTo { return $this->belongsTo(ReplenishmentRule::class, 'rule_id'); }
}
