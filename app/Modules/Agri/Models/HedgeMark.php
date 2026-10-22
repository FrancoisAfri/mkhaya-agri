<?php

namespace App\Modules\Agri\Models;

/** A hedge valued at the day's futures price. */
class HedgeMark extends AgriModel
{
    protected $table = 'agri_hedge_marks';

    protected $casts = ['mark_date' => 'date', 'price_date' => 'date', 'futures_price' => 'float', 'value_per_unit' => 'float', 'unrealised_pnl' => 'float'];
}
