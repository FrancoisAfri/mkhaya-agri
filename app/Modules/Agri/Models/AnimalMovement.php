<?php

namespace App\Modules\Agri\Models;


class AnimalMovement extends FarmOwnedModel
{
    protected $table = 'animal_movements';

    protected $casts = ['moved_on' => 'date'];
}
