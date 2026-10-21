<?php

namespace App\Modules\Agri\Models;


class AnimalEvent extends FarmOwnedModel
{
    public const TYPES = ['birth', 'weaning', 'mating', 'pregnancy_check', 'calving', 'lambing', 'kidding', 'milk_recording', 'exit', 'other'];

    protected $table = 'animal_events';

    protected $casts = ['occurred_on' => 'date', 'expected_on' => 'date', 'details' => 'array'];
}
