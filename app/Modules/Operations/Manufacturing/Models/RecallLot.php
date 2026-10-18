<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One lot a recall covers, with what was in stock and already dispatched when it was found. */
class RecallLot extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_recall_lots';

    protected $fillable = [
        'company_id', 'recall_id', 'lot_id', 'item_id', 'depth', 'in_stock_at_open', 'dispatched_at_open',
        'held', 'retrieved', 'disposed', 'released',
    ];

    protected $casts = [
        'depth' => 'integer', 'in_stock_at_open' => 'decimal:4', 'dispatched_at_open' => 'decimal:4', 'held' => 'decimal:4',
        'retrieved' => 'decimal:4', 'disposed' => 'decimal:4', 'released' => 'decimal:4',
    ];

    public function recall(): BelongsTo { return $this->belongsTo(Recall::class, 'recall_id'); }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockLot::class, 'lot_id')->withoutGlobalScopes();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'item_id')->withoutGlobalScopes();
    }
}
