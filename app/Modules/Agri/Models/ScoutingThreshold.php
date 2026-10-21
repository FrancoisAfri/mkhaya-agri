<?php

namespace App\Modules\Agri\Models;

/** When scouting observations add up to something that needs action, and how long before it escalates. */
class ScoutingThreshold extends AgriModel
{
    protected $table = 'scouting_thresholds';

    protected $casts = ['is_active' => 'boolean', 'min_incidence_pct' => 'float'];
}
