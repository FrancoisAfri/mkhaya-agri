<?php

namespace App\Modules\Agri\Models;

/** The operator's daily check before starting a machine (§7.3). A failed critical item stops the machine. */
class MachinePrestartCheck extends FarmOwnedModel
{
    use \App\Support\Eloquent\AliasesColumns;

    /** Fleet's table: a farm machine's record lives with its asset. */
    protected $table = 'fleet_prestart_checks';

    protected function columnAliases(): array
    {
        return ['machine_id' => 'vehicle_id'];
    }

    protected $casts = ['checked_on' => 'date', 'items' => 'array', 'passed' => 'boolean', 'hours_reading' => 'float', 'odometer_km' => 'float'];
}
