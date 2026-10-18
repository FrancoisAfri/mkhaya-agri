<?php

namespace App\Modules\Fleet\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class FleetVehicle extends Model
{
    use StoresFileBytes, KeepsDatabaseDefaults;

    /*
     * The document library, alongside this model's own documents() relation — a vehicle's licence
     * disc and roadworthy live in the repository where they can expire, be chased and be found by
     * anybody who needs them, not only from the vehicle page.
     */
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    use SoftDeletes, LogsActivity, HasFactory;

    protected static function newFactory(): \Database\Factories\Fleet\FleetVehicleFactory
    {
        return \Database\Factories\Fleet\FleetVehicleFactory::new();
    }

    protected $table = 'fleet_vehicles';

    protected $fillable = [
        'uuid', 'registration_number', 'fleet_number',
        'make_id', 'model_id', 'vehicle_type_id',
        'make', 'model', 'year',
        'vin_number', 'chassis_number', 'engine_number', 'colour', 'vehicle_type', 'fuel_type',
        'transmission', 'engine_capacity', 'seating_capacity', 'gvm', 'tank_size',
        'odometer_reading', 'hours_reading', 'status', 'status_reason', 'location',
        'company_unit_id', 'department_id', 'assigned_driver_id', 'key_safe_name',
        'purchase_date', 'purchase_price',
        'current_value', 'disposal_date', 'disposal_reason', 'disposal_type',
        'expected_disposal_date', 'co2_emissions_g_per_km',
        'registration_document_path', 'disposal_document_data',
        'approval_status', 'approved_by', 'approved_at',
        'notes', 'version', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'odometer_reading'       => 'decimal:1',
        'hours_reading'          => 'decimal:1',
        'engine_capacity'        => 'decimal:1',
        'purchase_price'         => 'decimal:2',
        'current_value'          => 'decimal:2',
        'co2_emissions_g_per_km' => 'decimal:2',
        'gvm'                    => 'decimal:2',
        'purchase_date'          => 'date',
        'disposal_date'          => 'date',
        'expected_disposal_date' => 'date',
        'approved_at'            => 'datetime',
    ];
    public function getRouteKeyName(): string
{
    return 'uuid';
}

    public function driver(): BelongsTo
    {
        return $this->belongsTo(FleetDriver::class, 'assigned_driver_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FleetBooking::class, 'vehicle_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FleetDocument::class, 'vehicle_id');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(FleetMaintenance::class, 'vehicle_id');
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FleetFuelLog::class, 'vehicle_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FleetNote::class, 'vehicle_id');
    }

    public function odometerLogs(): HasMany
    {
        return $this->hasMany(FleetOdometerLog::class, 'vehicle_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(FleetCard::class, 'vehicle_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(FleetVehicleImage::class, 'vehicle_id')->orderBy('sort_order');
    }

    public function keys(): HasMany
    {
        return $this->hasMany(FleetVehicleKey::class, 'vehicle_id')->orderByDesc('created_at');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FleetVehicleReminder::class, 'vehicle_id')->orderBy('start_date');
    }

    public function fireExtinguishers(): HasMany
    {
        return $this->hasMany(FleetFireExtinguisher::class, 'vehicle_id')->orderByDesc('created_at');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(FleetVehicleStatusChange::class, 'vehicle_id')->orderByDesc('created_at');
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(FleetVehicleChangeLog::class, 'vehicle_id')->orderByDesc('created_at');
    }


    public function scopeAvailable($q)     { return $q->where('status', 'available'); }
    public function scopeOnBooking($q)     { return $q->where('status', 'booked'); }
    public function scopeInMaintenance($q) { return $q->where('status', 'maintenance'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['registration_number', 'fleet_number', 'status', 'approval_status', 'assigned_driver_id', 'department_id', 'disposal_date', 'disposal_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function fileBlobColumn(): ?string
    {
        return 'disposal_document_data';
    }

    protected function filePathColumn(): string
    {
        return 'disposal_document_path';
    }

    protected function fileFolder(): string
    {
        return 'fleet/disposals';
    }
}
