<?php

namespace App\Modules\Agri\Models;

/** An operational weather threshold for an activity, crop, chemical or species — used only once approved (§11.2). */
class WeatherRule extends AgriModel
{
    public const ACTIVITIES = ['spraying', 'frost', 'irrigation', 'harvest_access', 'worker_heat', 'livestock_heat'];
    public const METRICS = ['wind_speed', 'wind_gust', 'rain_mm', 'rain_probability', 'temperature', 'temp_min', 'temp_max', 'humidity'];

    protected $table = 'agri_weather_rules';

    protected $casts = ['conditions' => 'array', 'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
