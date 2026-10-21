<?php

namespace App\Modules\Agri\Models;

class ObservationPhoto extends FarmOwnedModel
{
    protected $table = 'observation_photos';

    protected $hidden = ['data'];
}
