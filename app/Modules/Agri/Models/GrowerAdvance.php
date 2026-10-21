<?php

namespace App\Modules\Agri\Models;

/** Phase 2 §16D: outgrowers. */
class GrowerAdvance extends AgriModel
{
    protected $table = 'agri_grower_advances';

    protected $casts = ['quantity' => 'float', 'value' => 'float', 'recovered' => 'float', 'issued_on' => 'date'];
}
