<?php

namespace App\Modules\Agri\Models;

class SoilMoistureReading extends FarmOwnedModel
{
    protected $table = 'soil_moisture_readings';

    protected $casts = ['read_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'vwc_pct' => 'float'];
}
