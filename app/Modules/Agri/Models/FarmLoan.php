<?php

namespace App\Modules\Agri\Models;

/** A loan the farm repays — its instalments go into the cash-flow forecast. */
class FarmLoan extends AgriModel
{
    protected $table = 'farm_loans';

    protected $casts = ['principal' => 'float', 'annual_rate_pct' => 'float', 'payment' => 'float', 'first_payment_on' => 'date', 'is_active' => 'boolean'];
}
