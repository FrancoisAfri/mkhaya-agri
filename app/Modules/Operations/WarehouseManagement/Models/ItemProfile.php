<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The warehouse settings of an item that nothing else holds (WMS-010). */
class ItemProfile extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_item_profiles';

    protected $fillable = ['company_id', 'stock_item_id', 'description', 'brand', 'purchase_uom_id', 'storage_uom_id', 'issue_uom_id', 'is_variable_weight', 'safety_stock', 'max_stock', 'reorder_qty', 'lead_time_days', 'min_remaining_shelf_life_days', 'expiry_warning_days', 'temperature_min_c', 'temperature_max_c', 'hazard_class', 'requires_inspection', 'abc_class', 'abc_computed_at', 'loss_risk', 'rotation', 'expiry_alert_days'];

    protected $casts = ['is_variable_weight' => 'boolean', 'requires_inspection' => 'boolean', 'safety_stock' => 'decimal:4', 'max_stock' => 'decimal:4', 'reorder_qty' => 'decimal:4', 'temperature_min_c' => 'decimal:2', 'temperature_max_c' => 'decimal:2', 'expiry_alert_days' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'stock_item_id');
    }
}
