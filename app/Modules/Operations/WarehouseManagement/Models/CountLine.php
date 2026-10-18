<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One bin, item and lot to count, with what the ledger expected and what was accepted. */
class CountLine extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_count_lines';

    protected $fillable = ['company_id', 'count_id', 'bin_id', 'item_id', 'lot_id', 'snapshot_qty', 'expected_qty', 'counted_qty',
        'variance_qty', 'unit_cost', 'variance_value', 'round', 'status', 'unexpected', 'found_empty', 'causes', 'investigation',
        'adjustment_id', 'decided_by'];

    protected $casts = ['snapshot_qty' => 'decimal:4', 'expected_qty' => 'decimal:4', 'counted_qty' => 'decimal:4',
        'variance_qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'variance_value' => 'decimal:2', 'unexpected' => 'boolean',
        'found_empty' => 'boolean', 'causes' => 'array'];

    public const OPEN = 'OPEN';
    public const RECOUNT = 'RECOUNT';
    public const MATCHED = 'MATCHED';
    public const VARIANCE = 'VARIANCE';
    public const ACCEPTED = 'ACCEPTED';     // variance accepted, adjustment requested
    public const DISMISSED = 'DISMISSED';   // variance explained, no adjustment
    public const ADJUSTED = 'ADJUSTED';     // adjustment posted

    public function stockCount(): BelongsTo { return $this->belongsTo(Count::class, 'count_id'); }

    public function bin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'bin_id'); }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function entries(): HasMany { return $this->hasMany(CountEntry::class, 'count_line_id'); }

    public function adjustment(): BelongsTo { return $this->belongsTo(StockAdjustment::class, 'adjustment_id'); }
}
