<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One weight band of a carrier's service, for a zone or anywhere. */
class CarrierRate extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_carrier_rates';

    protected $fillable = ['company_id', 'carrier_id', 'service', 'zone', 'max_weight_kg', 'price', 'transit_days'];

    protected $casts = ['max_weight_kg' => 'float', 'price' => 'float', 'transit_days' => 'integer'];
}
