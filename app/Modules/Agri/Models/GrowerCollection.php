<?php

namespace App\Modules\Agri\Models;

/** Phase 2 §16D: outgrowers. */
class GrowerCollection extends AgriModel
{
    protected $table = 'agri_grower_collections';

    protected $casts = ['collected_on' => 'date', 'quantity' => 'float', 'rejected' => 'float', 'price' => 'float', 'value' => 'float'];
}
