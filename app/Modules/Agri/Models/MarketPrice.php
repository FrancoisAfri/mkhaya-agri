<?php

namespace App\Modules\Agri\Models;

/** A market price, always with its source, date, currency, market and unit (§9.4). A forecast is labelled as one. */
class MarketPrice extends AgriModel
{
    protected $table = 'agri_market_prices';

    protected $casts = ['price_date' => 'date', 'price' => 'float'];
}
