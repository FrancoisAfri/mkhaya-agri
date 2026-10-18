<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinRecurringInvoiceLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_recurring_invoice_lines';

    protected $fillable = [
        'entity_id', 'recurring_id', 'account_id', 'item_id', 'description', 'quantity', 'unit_price', 'tax_rate',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax_rate'   => 'decimal:3',
    ];
}
