<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinQuoteLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_quote_lines';

    protected $fillable = [
        'entity_id', 'quote_id', 'account_id', 'item_id', 'description', 'note', 'quantity', 'unit_price',
        'line_total', 'tax_code', 'tax_rate', 'tax_amount', 'unit', 'grade', 'price_basis',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
        'tax_rate'   => 'decimal:3',
        'tax_amount' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinItem::class, 'item_id');
    }
}
