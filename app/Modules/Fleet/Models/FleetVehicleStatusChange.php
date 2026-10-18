<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleStatusChange extends Model
{
    protected $table = 'fleet_vehicle_status_changes';

    protected $fillable = ['vehicle_id', 'from_status', 'to_status', 'reason', 'changed_by'];
}
