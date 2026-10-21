<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A farm machine is a Fleet asset (fleet_vehicles) working on a farm: Fleet keeps the asset - its
 * meters, servicing, faults, checks, fuel and documents - and the farm books it to jobs and costs
 * it by the hour. Farm code keeps its own names for Fleet's columns (see columnAliases).
 */
class FarmMachine extends FarmOwnedModel
{
    use \App\Support\Eloquent\AliasesColumns;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    // Farm records (Document Management) can be linked to it.
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    public const TYPES = ['tractor', 'harvester', 'sprayer', 'planter', 'implement', 'irrigation', 'pump', 'generator', 'vehicle', 'other'];

    /** Stored statuses. "Assigned" is not stored: it is an available machine with an approved booking now (§7.1). */
    public const AVAILABLE = 'available';
    public const ACTIVE = self::AVAILABLE;
    public const ASSIGNED = 'assigned';
    public const MAINTENANCE = 'maintenance';
    public const OUT_OF_SERVICE = 'offroad';   // Fleet's off-the-road status
    public const DISPOSED = 'disposed';
    public const STATUSES = [self::AVAILABLE, self::MAINTENANCE, self::OUT_OF_SERVICE, self::DISPOSED];
    public const UNAVAILABLE = [self::MAINTENANCE, self::OUT_OF_SERVICE, self::DISPOSED];

    public const OWNERSHIP = ['owned', 'leased', 'hired', 'shared'];

    protected $table = 'fleet_vehicles';

    protected function columnAliases(): array
    {
        return ['code' => 'fleet_number', 'machine_type' => 'vehicle_type', 'registration' => 'registration_number', 'purchase_cost' => 'purchase_price',
            'purchased_on' => 'purchase_date', 'disposed_on' => 'disposal_date', 'odometer_km' => 'odometer_reading'];
    }

    protected static function booted(): void
    {
        parent::booted();
        // Equipment unless it is a road vehicle; Fleet's defaults (sedan, petrol) do not describe a tractor.
        static::saving(function (FarmMachine $m) {
            $m->attributes['asset_class'] = ($m->attributes['vehicle_type'] ?? null) === 'vehicle' ? 'vehicle' : 'equipment';
            $m->attributes['fuel_type'] ??= 'diesel';
        });
    }

    protected $casts = [
        'hours_reading' => 'decimal:1', 'service_interval_hours' => 'decimal:1', 'next_service_hours' => 'decimal:1',
        'next_service_on' => 'date', 'hourly_rate' => 'decimal:2', 'purchase_price' => 'decimal:2',
        'purchase_date' => 'date', 'disposal_date' => 'date', 'disposal_value' => 'decimal:2',
        'lease_ends_on' => 'date', 'lease_payment' => 'float', 'warranty_until' => 'date', 'warranty_hours' => 'float', 'useful_life_hours' => 'float',
        'odometer_reading' => 'float', 'insurance_expires_on' => 'date', 'licence_expires_on' => 'date', 'inspection_expires_on' => 'date',
        'inspection_required' => 'boolean', 'expected_litres_per_hour' => 'float',
        'last_latitude' => 'float', 'last_longitude' => 'float', 'last_seen_at' => \App\Modules\Agri\Casts\FarmDateTime::class,
    ];

    public function usage(): HasMany
    {
        return $this->hasMany(MachineUsageLog::class, 'vehicle_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(MachineService::class, 'vehicle_id');
    }

    public function faults(): HasMany
    {
        return $this->hasMany(MachineFault::class, 'vehicle_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(WorkOrderMachine::class, 'machine_id');
    }

    public function isUnavailable(): bool
    {
        return in_array($this->status, self::UNAVAILABLE, true);
    }

    /** What the register shows: the stored status, or "assigned" while an approved booking covers now. */
    public function currentStatus(): string
    {
        if ($this->status !== self::AVAILABLE) {
            return $this->status;
        }
        $busy = WorkOrderMachine::withoutGlobalScopes()->where('machine_id', $this->id)->where('status', 'approved')
            ->where('booked_from', '<=', now())->where('booked_to', '>', now())->exists();

        return $busy ? self::ASSIGNED : self::AVAILABLE;
    }

    /** Under warranty on the date (and within the warranty hours, when there are any). */
    public function underWarranty(?\DateTimeInterface $on = null): bool
    {
        $on ??= today();

        return $this->warranty_until !== null && $this->warranty_until->gte($on)
            && ($this->warranty_hours === null || (float) $this->hours_reading <= $this->warranty_hours);
    }

    /** Due by hours or by date, whichever comes first. */
    public function serviceDue(int $horizonDays = 0, float $hoursMargin = 0): bool
    {
        $byHours = $this->next_service_hours !== null && (float) $this->hours_reading + $hoursMargin >= (float) $this->next_service_hours;
        $byDate = $this->next_service_on !== null && $this->next_service_on->lte(today()->addDays($horizonDays));

        return $byHours || $byDate;
    }
}
