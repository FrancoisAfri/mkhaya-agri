<?php

namespace App\Modules\Agri\Models;

class AgriFinanceExport extends AgriModel
{
    protected $table = 'agri_finance_exports';

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'total' => 'float'];
}
