<?php

namespace App\Modules\Agri\Models;


class AnimalWeight extends FarmOwnedModel
{
    protected $table = 'animal_weights';

    protected $casts = ['weighed_on' => 'date', 'weight_kg' => 'decimal:2', 'condition_score' => 'decimal:1'];
}
