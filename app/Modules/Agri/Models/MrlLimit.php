<?php

namespace App\Modules\Agri\Models;

/** A maximum residue limit: active ingredient × crop × market, in mg/kg, from a source, from a date. */
class MrlLimit extends AgriModel
{
    protected $table = 'agri_mrls';

    protected $casts = ['mrl_mg_kg' => 'float', 'effective_from' => 'date', 'is_starter' => 'boolean'];
}
