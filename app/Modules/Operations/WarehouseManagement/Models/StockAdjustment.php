<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A request to correct stock, posted to the ledger once approved where its risk requires it (WMS-024). */
class StockAdjustment extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_stock_adjustments';

    protected $fillable = ['override_id', 'uuid', 'company_id', 'number', 'warehouse_id', 'item_id', 'bin_id', 'lot_id', 'stock_status',
        'qty_delta', 'txn_uom_id', 'txn_qty', 'unit_cost', 'value_estimate', 'reason_code_id', 'notes', 'occurred_at',
        'status', 'approval_reasons', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'movement_id'];

    protected $casts = ['qty_delta' => 'decimal:4', 'txn_qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'value_estimate' => 'decimal:2',
        'occurred_at' => 'datetime', 'decided_at' => 'datetime', 'approval_reasons' => 'array'];

    public const PENDING = 'PENDING_APPROVAL';
    public const POSTED = 'POSTED';
    public const REJECTED = 'REJECTED';
    public const CANCELLED = 'CANCELLED';

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function bin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'bin_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function reason(): BelongsTo { return $this->belongsTo(ReasonCode::class, 'reason_code_id'); }

    public function movement(): BelongsTo { return $this->belongsTo(FinStockMovement::class, 'movement_id'); }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
