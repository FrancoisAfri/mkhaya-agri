<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * How MRP treats one material: made or bought, lead time, safety stock, minimum order and order
 * multiple, preferred supplier. Lead time and safety stock fall back to the warehouse item profile.
 */
class PlanningParam extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    protected $table = 'mfg_planning_params';

    protected $fillable = [
        'company_id', 'stock_item_id', 'make_or_buy', 'lead_time_days', 'safety_stock', 'min_order_qty', 'order_multiple',
        'preferred_supplier_id', 'is_planned',
    ];

    protected $casts = [
        'lead_time_days' => 'integer', 'safety_stock' => 'decimal:4', 'min_order_qty' => 'decimal:4', 'order_multiple' => 'decimal:4', 'is_planned' => 'boolean',
    ];
}
