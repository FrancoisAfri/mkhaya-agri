<?php

namespace App\Modules\Agri\Models;

/** Phase 2 §16D: outgrowers. */
class GrowerSettlement extends AgriModel
{
    protected $table = 'agri_grower_settlements';

    protected $casts = ['gross' => 'float', 'advances_recovered' => 'float', 'net' => 'float'];
}
