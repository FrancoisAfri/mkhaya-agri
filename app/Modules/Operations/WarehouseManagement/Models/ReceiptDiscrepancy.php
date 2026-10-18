<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Procurement\Models\GoodsReceivedItem;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One difference between what was ordered and what arrived (WMS-033), and how it was settled. */
class ReceiptDiscrepancy extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_receipt_discrepancies';

    protected $fillable = ['uuid', 'company_id', 'grn_id', 'grn_item_id', 'kind', 'expected', 'actual', 'within_tolerance',
        'detail', 'status', 'resolution', 'resolved_by', 'resolved_at', 'notified_at'];

    protected $casts = ['expected' => 'decimal:4', 'actual' => 'decimal:4', 'within_tolerance' => 'boolean',
        'resolved_at' => 'datetime', 'notified_at' => 'datetime'];

    public const KINDS = ['UNDER', 'OVER', 'WRONG_ITEM', 'DAMAGED', 'REJECTED', 'MISSING', 'DUPLICATE', 'SHORT_SHELF_LIFE', 'UNEXPECTED'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedItem::class, 'grn_item_id');
    }
}
