<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A booked delivery slot at a receiving dock (WMS-031). */
class DockAppointment extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_dock_appointments';

    protected $fillable = ['uuid', 'company_id', 'number', 'warehouse_id', 'dock_bin_id', 'purchase_order_id', 'supplier_id',
        'slot_start', 'slot_end', 'carrier', 'vehicle_reg', 'driver_name', 'driver_phone', 'status', 'arrived_at',
        'unloading_started_at', 'departed_at', 'assigned_user_id', 'notes', 'created_by'];

    protected $casts = ['slot_start' => 'datetime', 'slot_end' => 'datetime', 'arrived_at' => 'datetime',
        'unloading_started_at' => 'datetime', 'departed_at' => 'datetime'];

    public const STATUSES = ['BOOKED', 'ARRIVED', 'UNLOADING', 'COMPLETED', 'NO_SHOW', 'CANCELLED'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function dock(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'dock_bin_id'); }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }

    /** Booked, and the slot has ended without the vehicle arriving. */
    public function isOverdue(): bool
    {
        return $this->status === 'BOOKED' && $this->slot_end->isPast();
    }
}
