<?php

namespace App\Modules\Agri\Models;

/**
 * A boundary that changed both here and on the machine platform since they last agreed. Neither
 * is overwritten: both shapes are kept here until a person keeps ours or takes theirs.
 */
class MachineConflict extends AgriModel
{
    public const OPEN = 'open';

    protected $table = 'agri_machine_conflicts';

    protected $casts = ['ours' => 'array', 'theirs' => 'array', 'ours_ha' => 'float', 'theirs_ha' => 'float', 'resolved_at' => 'datetime'];
}
