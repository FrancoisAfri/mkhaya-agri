<?php

namespace App\Modules\Agri\Models;

/** Weather for a farm or field, from a named provider, for a stated period, fetched at a stated time (§11.1). */
class WeatherReading extends FarmOwnedModel
{
    protected $table = 'agri_weather';

    protected $casts = ['period_start' => \App\Modules\Agri\Casts\FarmDateTime::class, 'period_end' => \App\Modules\Agri\Casts\FarmDateTime::class, 'fetched_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'temperature' => 'float', 'temp_min' => 'float', 'temp_max' => 'float',
        'humidity' => 'float', 'wind_speed' => 'float', 'wind_gust' => 'float', 'rain_mm' => 'float', 'rain_probability' => 'integer', 'raw' => 'array', 'et0_mm' => 'float'];
}
