<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Shared\Traits\KeepsDatabaseDefaults;

class FleetBookingInspection extends Model
{
    use KeepsDatabaseDefaults;

    protected $table = 'fleet_booking_inspections';

    protected $fillable = [
        'booking_id', 'vehicle_id', 'inspection_type', 'inspector_id',
        'inspected_at', 'odometer_reading', 'fuel_level', 'condition_overall',
        'damage_markers', 'lights_ok', 'tyres_ok', 'brakes_ok', 'wipers_ok',
        'fluids_ok', 'first_aid_ok', 'fire_ext_ok', 'spare_tyre_ok', 'interior_ok',
        'notes', 'signature_path', 'photos', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'damage_markers'   => 'array',
        'photos'           => 'array',
        'inspected_at'     => 'datetime',
        'odometer_reading' => 'decimal:1',
        'lights_ok'        => 'boolean',
        'tyres_ok'         => 'boolean',
        'brakes_ok'        => 'boolean',
        'wipers_ok'        => 'boolean',
        'fluids_ok'        => 'boolean',
        'first_aid_ok'     => 'boolean',
        'fire_ext_ok'      => 'boolean',
        'spare_tyre_ok'    => 'boolean',
        'interior_ok'      => 'boolean',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(FleetBooking::class, 'booking_id'); }
    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function inspector(): BelongsTo { return $this->belongsTo(User::class, 'inspector_id'); }
}
