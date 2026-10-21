<?php

namespace App\Modules\Agri\Models;

class IrrigationEvent extends FarmOwnedModel
{
    protected $table = 'irrigation_events';

    protected $casts = ['planned_start' => \App\Modules\Agri\Casts\FarmDateTime::class, 'actual_start' => \App\Modules\Agri\Casts\FarmDateTime::class, 'planned_m3' => 'float', 'actual_m3' => 'float', 'pump_hours' => 'float', 'energy_kwh' => 'float', 'water_cost' => 'float', 'energy_cost' => 'float'];

    public function zone(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IrrigationZone::class, 'zone_id');
    }
}
