<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing a production run made: its item, lot, grade and share of the cost. */
class ProductionOrderOutput extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_production_order_outputs';

    protected $fillable = ['uuid', 'company_id', 'order_id', 'stock_item_id', 'warehouse_id', 'lot_id', 'movement_id', 'grade', 'qty', 'unit_cost', 'value', 'stock_status'];

    protected $casts = ['qty' => 'float', 'unit_cost' => 'float', 'value' => 'float'];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'order_id'); }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'stock_item_id'); }

    public function lot(): BelongsTo { return $this->belongsTo(FinStockLot::class, 'lot_id'); }
}
