<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmMachine extends FarmOwnedModel
{
    public const TYPES = ['tractor', 'harvester', 'sprayer', 'planter', 'implement', 'irrigation', 'pump', 'generator', 'vehicle', 'other'];

    public const ACTIVE = 'active';
    public const MAINTENANCE = 'maintenance';
    public const DISPOSED = 'disposed';
    public const STATUSES = [self::ACTIVE, self::MAINTENANCE, self::DISPOSED];

    protected $table = 'farm_machines';

    protected $casts = [
        'hours_reading' => 'decimal:1', 'service_interval_hours' => 'decimal:1', 'next_service_hours' => 'decimal:1',
        'next_service_on' => 'date', 'hourly_rate' => 'decimal:2', 'purchase_cost' => 'decimal:2',
        'purchased_on' => 'date', 'disposed_on' => 'date', 'disposal_value' => 'decimal:2',
    ];

    public function usage(): HasMany
    {
        return $this->hasMany(MachineUsageLog::class, 'machine_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(MachineService::class, 'machine_id');
    }

    /** Due by hours or by date, whichever comes first. */
    public function serviceDue(int $horizonDays = 0, float $hoursMargin = 0): bool
    {
        $byHours = $this->next_service_hours !== null && (float) $this->hours_reading + $hoursMargin >= (float) $this->next_service_hours;
        $byDate = $this->next_service_on !== null && $this->next_service_on->lte(today()->addDays($horizonDays));

        return $byHours || $byDate;
    }
}
