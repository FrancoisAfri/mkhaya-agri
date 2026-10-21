<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Casts\FarmDateTime;

/** A temperature / humidity reading in a store; one out of range waits for a documented corrective action (§8.3). */
class StorageReading extends FarmOwnedModel
{
    protected $table = 'storage_readings';

    protected $casts = ['recorded_at' => FarmDateTime::class, 'acted_at' => FarmDateTime::class, 'temperature' => 'float', 'humidity' => 'float',
        'other' => 'array', 'out_of_range' => 'boolean'];
}
