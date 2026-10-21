<?php

namespace App\Modules\Agri\Models;

/** Produce offered for sale: in stock (backed by lots) or forecast (backed by a crop), never confused (§9.3). */
class MarketListing extends FarmOwnedModel
{
    protected $table = 'agri_market_listings';

    protected $casts = ['quantity' => 'float', 'lot_ids' => 'array', 'available_from' => 'date', 'asking_price' => 'float'];
}
