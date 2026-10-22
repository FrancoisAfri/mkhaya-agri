<?php

namespace App\Modules\Agri\Models;

/** One monitored value in a carbon project's period (a soil-carbon sample, fuel used, area under practice…). */
class CarbonMonitoring extends FarmOwnedModel
{
    protected $table = 'agri_carbon_monitoring';

    protected $casts = ['measured_on' => 'date', 'value' => 'float'];
}
