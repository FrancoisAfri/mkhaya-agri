<?php

namespace App\Modules\Agri\Models;

/** One zone of a prescription: its shape, its class, its rate and what it needs. */
class PrescriptionZone extends FarmOwnedModel
{
    protected $table = 'agri_prescription_zones';

    protected $casts = ['boundary' => 'array', 'area_ha' => 'float', 'rate' => 'float', 'quantity' => 'float', 'cost' => 'float', 'ndvi' => 'float'];
}
