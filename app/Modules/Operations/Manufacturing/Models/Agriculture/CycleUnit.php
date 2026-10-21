<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Which unit a cycle was in, and when: a flock moved from the brooder to a grow-out house keeps both. */
class CycleUnit extends ProductionModel
{
    protected $table = 'prd_cycle_units';

    protected $casts = ['from_at' => 'datetime', 'to_at' => 'datetime'];
}
