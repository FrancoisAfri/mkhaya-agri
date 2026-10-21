<?php

namespace App\Modules\Agri\Models;

/** A frost, heat, storm or severe-weather warning issued by a provider for a farm (§11.1). */
class WeatherWarning extends FarmOwnedModel
{
    protected $table = 'agri_weather_warnings';

    protected $casts = ['starts_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'ends_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'fetched_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
