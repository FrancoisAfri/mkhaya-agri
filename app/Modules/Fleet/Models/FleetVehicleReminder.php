<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleReminder extends Model
{
    protected $table = 'fleet_vehicle_reminders';

    protected $fillable = [
        'vehicle_id', 'name', 'description',
        'start_date', 'end_date', 'notified_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'notified_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }
}
