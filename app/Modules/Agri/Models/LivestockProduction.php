<?php

namespace App\Modules\Agri\Models;

/** Milk, eggs, wool: what an animal or a group produced on a day — and whether it may be sold. */
class LivestockProduction extends FarmOwnedModel
{
    protected $table = 'livestock_production';

    protected $casts = ['recorded_on' => 'date', 'quantity' => 'float', 'withheld' => 'boolean', 'value' => 'float'];
}
