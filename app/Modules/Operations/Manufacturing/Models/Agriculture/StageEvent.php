<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A cycle moving from one stage of its type to the next. */
class StageEvent extends ProductionModel
{
    protected $table = 'prd_stage_events';

    protected $casts = ['at' => 'datetime'];
}
