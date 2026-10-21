<?php

namespace App\Modules\Agri\Models;

/** An estimate of a crop's harvest — never a measurement; harvest records are what was measured. */
class HarvestForecast extends FarmOwnedModel
{
    protected $table = 'harvest_forecasts';

    protected $casts = ['made_on' => 'date', 'ready_on' => 'date', 'quantity' => 'float', 'low' => 'float', 'high' => 'float', 'basis' => 'array'];
}
