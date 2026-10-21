<?php

namespace App\Modules\Agri\Models;

/** Phase 2 §16D: outgrowers. */
class GrowerContract extends AgriModel
{
    protected $table = 'agri_grower_contracts';

    protected $casts = ['hectares' => 'float', 'expected_quantity' => 'float', 'price' => 'float', 'grade_prices' => 'array', 'advance_limit' => 'float'];
}
