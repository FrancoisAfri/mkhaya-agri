<?php

namespace App\Modules\Agri\Models;

/** One active ingredient's measured residue in a test. */
class ResidueResult extends FarmOwnedModel
{
    protected $table = 'agri_residue_results';

    protected $casts = ['measured_mg_kg' => 'float', 'loq_mg_kg' => 'float', 'below_loq' => 'boolean'];
}
