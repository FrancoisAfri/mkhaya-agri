<?php

namespace App\Modules\Agri\Models;

/** What a buyer is promised: so much, of a grade, by a date. */
class HarvestCommitment extends FarmOwnedModel
{
    protected $table = 'harvest_commitments';

    protected $casts = ['deliver_on' => 'date', 'quantity' => 'float', 'delivered_quantity' => 'float', 'price_per_unit' => 'float'];
}
