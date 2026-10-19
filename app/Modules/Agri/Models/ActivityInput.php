<?php

namespace App\Modules\Agri\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seed, fertiliser, chemical, feed or fuel used on an activity. The stock itself moves in the shared
 * stock ledger; this row says which movement fed which activity and at what cost.
 */
class ActivityInput extends FarmOwnedModel
{
    public const PLANNED = 'planned';
    public const ISSUED = 'issued';
    public const REVERSED = 'reversed';

    protected $table = 'activity_inputs';

    protected $casts = [
        'quantity' => 'decimal:4', 'rate_per_ha' => 'decimal:4', 'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2', 'issued_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(FieldActivity::class, 'activity_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'stock_item_id')->withoutGlobalScopes();
    }
}
