<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderMaterial extends FarmOwnedModel
{
    protected $table = 'work_order_materials';

    protected $casts = ['planned_quantity' => 'float', 'actual_quantity' => 'float', 'rate_per_ha' => 'float', 'cost' => 'float'];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')->withoutGlobalScopes();
    }
}
