<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Move received (or otherwise staged) stock to where it is kept (WMS-040/041). */
class PutawayTask extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_putaway_tasks';

    protected $fillable = ['uuid', 'company_id', 'number', 'warehouse_id', 'item_id', 'lot_id', 'stock_status', 'from_bin_id',
        'suggested_bin_id', 'to_bin_id', 'qty', 'done_qty', 'status', 'priority', 'source_type', 'source_id', 'assigned_to',
        'completed_by', 'started_at', 'completed_at', 'exception_note', 'suggestion_reasons'];

    protected $casts = ['qty' => 'decimal:4', 'done_qty' => 'decimal:4', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'suggestion_reasons' => 'array'];

    public const OPEN = 'OPEN';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const DONE = 'DONE';
    public const PARTIAL = 'PARTIAL';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }

    public function fromBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'from_bin_id'); }

    public function suggestedBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'suggested_bin_id'); }

    public function toBin(): BelongsTo { return $this->belongsTo(FinStockBin::class, 'to_bin_id'); }

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
