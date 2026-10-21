<?php

namespace App\Modules\Agri\Models;

/** Capital spending planned: a tractor, a pivot, a shed. */
class FarmCapexPlan extends AgriModel
{
    protected $table = 'farm_capex_plans';

    protected $casts = ['amount' => 'float', 'planned_on' => 'date'];
}
