<?php

namespace App\Modules\Agri\Models;

/** A buyer asking for produce (§9.3). */
class MarketRequest extends AgriModel
{
    protected $table = 'agri_market_requests';

    protected $casts = ['quantity' => 'float', 'needed_by' => 'date', 'target_price' => 'float'];
}
