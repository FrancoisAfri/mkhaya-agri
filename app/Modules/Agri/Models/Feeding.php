<?php

namespace App\Modules\Agri\Models;

/** Feed given to a group or an animal, issued from the farm store and costed. */
class Feeding extends FarmOwnedModel
{
    protected $table = 'feedings';

    protected $casts = ['fed_on' => 'date', 'quantity_kg' => 'float', 'cost' => 'float', 'issues' => 'array'];
}
