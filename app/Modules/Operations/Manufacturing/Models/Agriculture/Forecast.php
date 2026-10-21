<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A prediction with the model and version that made it, its interval, and — once known — the actual. */
class Forecast extends ProductionModel
{
    protected $table = 'prd_forecasts';

    protected $casts = ['inputs' => 'array', 'target_on' => 'date', 'made_at' => 'datetime', 'resolved_at' => 'datetime', 'within_interval' => 'boolean', 'predicted' => 'float', 'lower' => 'float', 'upper' => 'float', 'actual' => 'float', 'error' => 'float', 'abs_pct_error' => 'float'];
}
