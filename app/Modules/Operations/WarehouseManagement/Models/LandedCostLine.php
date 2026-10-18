<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostLine extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_landed_cost_lines';

    protected $fillable = ['company_id', 'landed_cost_id', 'item_id', 'movement_id', 'qty', 'base_value', 'weight_kg', 'allocated', 'to_inventory', 'to_cogs'];

    protected $casts = ['qty' => 'decimal:4', 'base_value' => 'decimal:2', 'weight_kg' => 'decimal:4', 'allocated' => 'decimal:2', 'to_inventory' => 'decimal:2', 'to_cogs' => 'decimal:2'];

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }
}
