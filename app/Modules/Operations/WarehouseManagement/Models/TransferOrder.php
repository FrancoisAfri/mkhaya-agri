<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Stock going from one warehouse to another (WMS-051). */
class TransferOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_transfer_orders';

    protected $fillable = ['uuid', 'company_id', 'number', 'from_warehouse_id', 'to_warehouse_id', 'status', 'reason', 'priority',
        'needed_by', 'carrier', 'vehicle_reg', 'tracking_reference', 'created_by', 'approved_by', 'approved_at', 'picked_at',
        'dispatched_at', 'received_at', 'reconciled_at', 'cancel_reason', 'source'];

    protected $casts = ['needed_by' => 'date', 'approved_at' => 'datetime', 'picked_at' => 'datetime', 'dispatched_at' => 'datetime',
        'received_at' => 'datetime', 'reconciled_at' => 'datetime'];

    public const STATUSES = ['DRAFT', 'APPROVED', 'PICKED', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'RECONCILED', 'CANCELLED'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function lines(): HasMany { return $this->hasMany(TransferLine::class, 'transfer_id'); }

    public function from(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'from_warehouse_id'); }

    public function to(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'to_warehouse_id'); }
}
