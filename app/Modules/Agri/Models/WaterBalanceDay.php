<?php

namespace App\Modules\Agri\Models;

/** One day of an irrigation zone's (or field's) root-zone water balance, observed or forecast. */
class WaterBalanceDay extends FarmOwnedModel
{
    protected $table = 'agri_water_balance';

    protected $casts = ['day' => 'date', 'is_forecast' => 'boolean', 'reset_by_sensor' => 'boolean', 'et0_mm' => 'float', 'kc' => 'float', 'etc_mm' => 'float',
        'rain_mm' => 'float', 'effective_rain_mm' => 'float', 'irrigation_mm' => 'float', 'depletion_mm' => 'float', 'taw_mm' => 'float', 'raw_mm' => 'float',
        'sensor_vwc_pct' => 'float'];
}
