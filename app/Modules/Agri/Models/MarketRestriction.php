<?php

namespace App\Modules\Agri\Models;

/** An active ingredient banned or restricted in a market. A banned active applied to a lot's crop stops it going there. */
class MarketRestriction extends AgriModel
{
    public const STATUSES = ['banned', 'restricted'];

    protected $table = 'agri_market_restrictions';

    protected $casts = ['effective_from' => 'date', 'is_starter' => 'boolean'];
}
