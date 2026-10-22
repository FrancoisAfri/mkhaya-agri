<?php

namespace App\Modules\Agri\Models;

/** Credits a registry issued for a vintage, with the share withheld in its buffer pool. */
class CarbonIssuance extends FarmOwnedModel
{
    protected $table = 'agri_carbon_issuances';

    protected $casts = ['issued_on' => 'date', 'quantity_t' => 'float', 'buffer_t' => 'float', 'vintage' => 'integer'];
}
