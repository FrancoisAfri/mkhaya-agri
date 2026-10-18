<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinSalesOrderLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_sales_order_lines';

    protected $fillable = [
        'entity_id', 'order_id', 'account_id', 'item_id', 'description', 'quantity', 'unit_price',
        'line_total', 'tax_rate', 'tax_amount', 'qty_delivered',
    ];

    protected $casts = [
        'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2',
        'tax_rate' => 'decimal:3', 'tax_amount' => 'decimal:2', 'qty_delivered' => 'decimal:4',
    ];

    public function outstandingQty(): float
    {
        return round((float) $this->quantity - (float) $this->qty_delivered, 4);
    }
}
