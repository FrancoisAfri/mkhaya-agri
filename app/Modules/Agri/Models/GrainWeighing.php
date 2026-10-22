<?php

namespace App\Modules\Agri\Models;

/** One pass over the weighbridge: a loaded (gross) or empty (tare) truck, from the device or captured by hand. */
class GrainWeighing extends AgriModel
{
    protected $table = 'agri_grain_weighings';

    protected $casts = ['mass_kg' => 'float', 'weighed_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
