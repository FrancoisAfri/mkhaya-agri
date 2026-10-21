<?php

namespace App\Modules\Agri\Models;

/** How shared overheads are spread over crops: per hectare, or a percentage of direct costs. */
class OverheadRule extends AgriModel
{
    protected $table = 'overhead_rules';

    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date', 'rate' => 'float', 'is_active' => 'boolean'];
}
