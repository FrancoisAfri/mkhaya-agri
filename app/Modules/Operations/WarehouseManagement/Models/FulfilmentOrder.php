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

/** Demand to be picked, packed and shipped, from any source (WMS-060). */
class FulfilmentOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_orders';

    protected $fillable = ['uuid', 'company_id', 'number', 'warehouse_id', 'source', 'external_ref', 'source_id', 'customer_party_id', 'customer_name', 'ship_to', 'priority', 'required_by', 'sla_due_at', 'sla_at_risk_at', 'allow_partial', 'allow_substitution', 'status', 'carrier', 'notes', 'cancel_reason', 'created_by', 'released_at', 'pick_started_at', 'picked_at', 'packed_at', 'dispatched_at', 'delivered_at'];

    protected $casts = ['ship_to' => 'array', 'required_by' => 'datetime', 'sla_due_at' => 'datetime', 'sla_at_risk_at' => 'datetime', 'allow_partial' => 'boolean', 'allow_substitution' => 'boolean', 'released_at' => 'datetime', 'pick_started_at' => 'datetime', 'picked_at' => 'datetime', 'packed_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public const SOURCES = ['SALES_ORDER', 'ECOMMERCE', 'INTERNAL', 'MANUFACTURING', 'API'];

    /** States in which the order still holds or wants stock. */
    public const LIVE = ['RELEASED', 'ALLOCATED', 'PARTIALLY_ALLOCATED', 'BACKORDERED', 'PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED'];

    public function lines(): HasMany { return $this->hasMany(FulfilmentLine::class, 'order_id')->orderBy('line_no'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function packages(): HasMany { return $this->hasMany(Package::class, 'order_id'); }

    public function shipments(): HasMany { return $this->hasMany(Shipment::class, 'order_id'); }
}
