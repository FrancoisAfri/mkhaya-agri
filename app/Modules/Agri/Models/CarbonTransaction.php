<?php

namespace App\Modules\Agri\Models;

/** Credits retired or transferred out of an issuance. */
class CarbonTransaction extends FarmOwnedModel
{
    protected $table = 'agri_carbon_transactions';

    protected $casts = ['transacted_on' => 'date', 'quantity_t' => 'float'];
}
