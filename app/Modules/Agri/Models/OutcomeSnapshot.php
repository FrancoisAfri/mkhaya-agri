<?php

namespace App\Modules\Agri\Models;

/** A farm's outcome measures for a period; the baseline is the one the others are measured against (§19). */
class OutcomeSnapshot extends FarmOwnedModel
{
    protected $table = 'agri_outcome_snapshots';

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'measures' => 'array', 'is_baseline' => 'boolean'];
}
