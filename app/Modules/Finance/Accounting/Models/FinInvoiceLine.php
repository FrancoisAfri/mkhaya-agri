<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinInvoiceLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_invoice_lines';

    protected $fillable = [
        'entity_id', 'invoice_id', 'account_id', 'item_id', 'description', 'note', 'quantity', 'unit_price',
        'discount_rate', 'discount_amount', 'line_total', 'tax_code', 'tax_rate', 'tax_amount',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_rate'   => 'decimal:3',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'tax_rate'   => 'decimal:3',
        'tax_amount' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }
}
