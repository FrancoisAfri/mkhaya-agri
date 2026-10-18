<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** What happened to a shipment after it left, from the carrier or entered by a person. */
class TrackingEvent extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['BOOKED', 'COLLECTED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'FAILED', 'RETURNED', 'EXCEPTION'];

    public const UPDATED_AT = null;

    protected $table = 'wms_tracking_events';

    protected $fillable = ['company_id', 'shipment_id', 'status', 'occurred_at', 'location', 'detail', 'source', 'external_id', 'recorded_by'];

    protected $casts = ['occurred_at' => 'datetime'];
}
