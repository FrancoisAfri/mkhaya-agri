<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;

class FleetVehicleChangeLog extends Model
{
    protected $table = 'fleet_vehicle_change_logs';

    protected $fillable = ['vehicle_id', 'field_name', 'old_value', 'new_value', 'changed_by'];
}
