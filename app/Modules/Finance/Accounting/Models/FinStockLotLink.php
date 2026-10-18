<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lot made from part of another: a split (one parent, a new child) or a merge (several parents,
 * one child). Production genealogy is read from movements instead; see Traceability.
 */
class FinStockLotLink extends Model
{
    use BelongsToCompany;

    public const SPLIT = 'SPLIT';
    public const MERGE = 'MERGE';

    protected $table = 'fin_stock_lot_links';

    protected $fillable = ['company_id', 'entity_id', 'parent_lot_id', 'child_lot_id', 'kind', 'qty', 'movement_id', 'created_by'];

    protected $casts = ['qty' => 'decimal:4'];

    public function parent(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'parent_lot_id')->withoutGlobalScopes(); }

    public function child(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'child_lot_id')->withoutGlobalScopes(); }
}
