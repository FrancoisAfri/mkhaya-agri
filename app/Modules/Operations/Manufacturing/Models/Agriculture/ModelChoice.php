<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** The forecast model currently trusted for a measure: the one with the lowest error on what actually happened. */
class ModelChoice extends ProductionModel
{
    protected $table = 'prd_model_choices';

    protected $casts = ['compared' => 'array', 'chosen_at' => 'datetime', 'mape' => 'float'];
}
