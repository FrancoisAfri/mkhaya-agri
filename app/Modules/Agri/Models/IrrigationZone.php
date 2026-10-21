<?php

namespace App\Modules\Agri\Models;

class IrrigationZone extends FarmOwnedModel
{
    protected $table = 'irrigation_zones';

    protected $casts = ['is_active' => 'boolean', 'flow_m3_per_hour' => 'float', 'water_cost_per_m3' => 'float', 'energy_kwh_per_hour' => 'float', 'energy_cost_per_kwh' => 'float', 'area_ha' => 'float'];

    public function field(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FarmField::class, 'field_id');
    }
}
