<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FleetFuelLog extends Model
{
    use SoftDeletes, LogsActivity, HasFactory;

    protected static function newFactory(): \Database\Factories\Fleet\FleetFuelLogFactory
    {
        return \Database\Factories\Fleet\FleetFuelLogFactory::new();
    }

    protected $table = 'fleet_fuel_logs';

    protected $fillable = [
        'vehicle_id', 'booking_id', 'driver_id', 'fleet_card_id', 'filled_at', 'fuel_type',
        'litres', 'cost_per_litre', 'total_cost', 'odometer_reading',
        'station_name', 'station_city', 'full_tank', 'consumption_per_100km',
        'notes', 'receipt_path', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'filled_at'             => 'datetime',
        'litres'                => 'decimal:3',
        'cost_per_litre'        => 'decimal:4',
        'total_cost'            => 'decimal:2',
        'odometer_reading'      => 'decimal:1',
        'consumption_per_100km' => 'decimal:2',
        'full_tank'             => 'boolean',
    ];

    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function driver(): BelongsTo  { return $this->belongsTo(FleetDriver::class, 'driver_id'); }
    public function card(): BelongsTo    { return $this->belongsTo(FleetCard::class, 'fleet_card_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['vehicle_id', 'driver_id', 'fuel_type', 'litres', 'total_cost', 'odometer_reading', 'station_name', 'filled_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
