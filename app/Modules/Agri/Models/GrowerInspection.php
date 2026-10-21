<?php

namespace App\Modules\Agri\Models;

/** Phase 2 §16D: outgrowers. */
class GrowerInspection extends AgriModel
{
    protected $table = 'agri_grower_inspections';

    protected $casts = ['inspected_on' => 'date', 'estimated_quantity' => 'float'];
}
