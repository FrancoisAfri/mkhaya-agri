<?php

namespace App\Modules\Agri\Models;

/** One of a farm's stores: a warehouse in the shared inventory, known to the farm by its kind. */
class FarmStore extends FarmOwnedModel
{
    protected $table = 'farm_stores';

    public const KINDS = ['main', 'silo', 'cold_room', 'chemical_store', 'fuel_tank', 'feed_store', 'workshop', 'packhouse', 'other'];

    protected $casts = ['allowed_storage_classes' => 'array', 'restricted' => 'boolean', 'is_active' => 'boolean', 'capacity' => 'float',
        'temperature_min' => 'float', 'temperature_max' => 'float', 'humidity_min' => 'float', 'humidity_max' => 'float'];
}
