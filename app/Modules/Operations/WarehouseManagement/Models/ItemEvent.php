<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in an item's history: lifecycle, barcodes, packaging. The database refuses edits. */
class ItemEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_item_events';

    public $timestamps = false;

    protected $fillable = ['company_id', 'stock_item_id', 'action', 'changes', 'reason', 'actor_id', 'created_at'];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'actor_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'stock_item_id');
    }
}
