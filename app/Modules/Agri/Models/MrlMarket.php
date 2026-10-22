<?php

namespace App\Modules\Agri\Models;

/**
 * A market produce is sold into, for residue purposes: the countries it covers, the limit that
 * applies where no specific MRL is set, and whether a residue result is required before produce
 * may ship there.
 */
class MrlMarket extends AgriModel
{
    protected $table = 'agri_mrl_markets';

    protected $casts = ['countries' => 'array', 'default_mrl_mg_kg' => 'float', 'requires_result' => 'boolean', 'default_target' => 'boolean',
        'is_active' => 'boolean', 'is_system' => 'boolean'];
}
