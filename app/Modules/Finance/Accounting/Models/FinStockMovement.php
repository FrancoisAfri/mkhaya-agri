<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FinStockMovement extends Model
{
    use BelongsToCompany, BelongsToEntity;

    protected $table = 'fin_stock_movements';

    /*
     * company_id, warehouse_id and to_warehouse_id were missing from this list, so Laravel dropped
     * them without a word: InventoryService passed the warehouse on every movement and every row was
     * written without one. A movement that cannot say where it happened cannot be traced (MFG-008
     * control 4).
     */
    protected $fillable = [
        'entity_id', 'company_id', 'uuid', 'item_id', 'warehouse_id', 'to_warehouse_id', 'type', 'qty',
        'unit_cost', 'value', 'reference', 'movement_date', 'journal_id', 'created_by',
        'lot_id', 'to_lot_id', 'bin_id', 'to_bin_id', 'stock_status', 'to_stock_status', 'source_type', 'source_id',
        'idempotency_key', 'reversal_of_id',
        'txn_uom_id', 'txn_qty', 'reason_code', 'occurred_at', 'approved_by', 'approval_reference', 'handling_unit',
    ];

    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'value' => 'decimal:2', 'movement_date' => 'date', 'txn_qty' => 'decimal:4', 'occurred_at' => 'datetime'];

    public function lot(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FinStockLot::class, 'lot_id');
    }

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'item_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FinWarehouse::class, 'warehouse_id')->withoutGlobalScopes();
    }

    protected static function booted(): void
    {
        static::creating(fn (FinStockMovement $m) => $m->uuid ??= (string) Str::uuid());
    }
}
