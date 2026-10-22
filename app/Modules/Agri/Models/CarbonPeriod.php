<?php

namespace App\Modules\Agri\Models;

/** A monitoring period of a registered carbon project: what was measured, claimed and verified. */
class CarbonPeriod extends FarmOwnedModel
{
    protected $table = 'agri_carbon_periods';

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'verified_on' => 'date', 'claimed_t' => 'float', 'verified_t' => 'float'];
}
