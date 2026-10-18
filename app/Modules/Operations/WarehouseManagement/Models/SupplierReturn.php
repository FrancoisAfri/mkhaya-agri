<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Goods going back to a supplier (WMS-035), from approval to acknowledgement and credit. */
class SupplierReturn extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_supplier_returns';

    protected $fillable = ['uuid', 'company_id', 'number', 'supplier_id', 'warehouse_id', 'grn_id', 'status', 'reason', 'carrier',
        'dispatch_reference', 'dispatched_at', 'acknowledged_at', 'acknowledged_by_name', 'outcome', 'credit_reference',
        'created_by', 'approved_by'];

    protected $casts = ['dispatched_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function lines(): HasMany { return $this->hasMany(SupplierReturnLine::class, 'return_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function grn(): BelongsTo { return $this->belongsTo(GoodsReceivedNote::class, 'grn_id'); }
}
