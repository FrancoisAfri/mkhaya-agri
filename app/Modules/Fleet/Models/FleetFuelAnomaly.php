<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetFuelAnomaly extends Model
{
    protected $table = 'fleet_fuel_anomalies';

    protected $fillable = [
        'fuel_log_id', 'tollgate_id', 'vehicle_id', 'driver_id',
        'anomaly_type', 'description', 'severity',
        'expected_value', 'actual_value',
        'is_reviewed', 'reviewed_by', 'reviewed_at', 'resolution',
    ];

    protected $casts = [
        'expected_value' => 'decimal:2',
        'actual_value'   => 'decimal:2',
        'is_reviewed'    => 'boolean',
        'reviewed_at'    => 'datetime',
    ];

    public function vehicle(): BelongsTo   { return $this->belongsTo(FleetVehicle::class); }
    public function driver(): BelongsTo    { return $this->belongsTo(FleetDriver::class); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function scopeUnreviewed($q) { return $q->where('is_reviewed', false); }
}
