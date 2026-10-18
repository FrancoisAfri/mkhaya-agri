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

/** A dispatch: which packages left, with whom, and proof of handover and delivery (WMS-065). */
class Shipment extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_shipments';

    protected $fillable = ['uuid', 'company_id', 'number', 'order_id', 'warehouse_id', 'carrier', 'service', 'vehicle_reg', 'driver_name', 'tracking_reference', 'collection_at', 'dispatched_at', 'handover_name', 'handover_signature_path', 'delivery_note_id', 'status', 'delivered_at', 'pod_received_by', 'pod_path', 'pod_note', 'dispatched_by',
        'carrier_id', 'booking_reference', 'booked_at', 'quoted_price', 'tracking_status', 'tracked_at'];

    protected $casts = ['collection_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'booked_at' => 'datetime', 'tracked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function order(): BelongsTo { return $this->belongsTo(FulfilmentOrder::class, 'order_id'); }

    public function packages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'wms_shipment_packages', 'shipment_id', 'package_id');
    }

    public function carrierRecord(): BelongsTo { return $this->belongsTo(Carrier::class, 'carrier_id'); }

    public function trackingEvents(): HasMany { return $this->hasMany(TrackingEvent::class, 'shipment_id')->orderBy('occurred_at'); }
}
