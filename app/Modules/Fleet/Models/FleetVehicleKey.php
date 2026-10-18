<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleKey extends Model
{
    protected $table = 'fleet_vehicle_keys';

    protected $fillable = [
        'vehicle_id', 'key_number', 'key_type', 'key_status',
        'description', 'issued_to_type', 'issued_to_employee_id',
        'safe_id', 'date_issued', 'issued_by_id',
        'reason_for_loss', 'captured_by', 'created_by',
    ];

    protected $casts = [
        'date_issued' => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }
}
