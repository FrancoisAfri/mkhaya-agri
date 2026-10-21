<?php

namespace App\Modules\Agri\Models;

/** A production plan and its alternatives, compared before one is approved (§16E). */
class Simulation extends FarmOwnedModel
{
    protected $table = 'agri_simulations';

    protected $casts = ['base' => 'array', 'scenarios' => 'array', 'results' => 'array'];
}
