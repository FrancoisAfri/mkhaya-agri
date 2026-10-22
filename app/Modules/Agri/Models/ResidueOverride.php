<?php

namespace App\Modules\Agri\Models;

/**
 * An authorised release of a load the residue gate stopped, for one market: who, why, and what the
 * gate said at the time. Audited like every farm record.
 */
class ResidueOverride extends FarmOwnedModel
{
    protected $table = 'agri_residue_overrides';

    protected $casts = ['blocks' => 'array'];
}
