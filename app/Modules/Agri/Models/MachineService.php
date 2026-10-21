<?php

namespace App\Modules\Agri\Models;


class MachineService extends FarmOwnedModel
{
    public const TYPES = ['service', 'repair', 'inspection'];

    use \App\Support\Eloquent\AliasesColumns;

    /** Fleet's table: a farm machine's record lives with its asset. */
    protected $table = 'fleet_maintenance';

    protected function columnAliases(): array
    {
        return ['machine_id' => 'vehicle_id', 'serviced_on' => 'completed_date', 'service_type' => 'maintenance_type', 'provider' => 'service_provider', 'recorded_by' => 'created_by'];
    }

    protected $casts = ['completed_date' => 'date', 'hours_at_service' => 'decimal:1', 'cost' => 'decimal:2'];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(fn (MachineService $s) => $s->attributes['status'] ??= 'completed');
    }
}
