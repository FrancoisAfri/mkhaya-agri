<?php

namespace App\Modules\Assets\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parts/consumables line on a work order.
 *
 * part_id is a loose, FK-less reference to a spare part in the parallel
 * asset_parts feature. The line always carries its own free-text description,
 * quantity and unit_cost so it is fully self-describing without that feature.
 */
class AssetWorkOrderPart extends Model
{
    use BelongsToCompany, Uuids;

    protected $table = 'asset_work_order_parts';

    protected $fillable = [
        'uuid',
        'company_id',
        'work_order_id',
        'part_id',
        'description',
        'quantity',
        'unit_cost',
        'line_cost',
    ];

    protected $casts = [
        'quantity'  => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_cost' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(AssetWorkOrder::class, 'work_order_id');
    }
}
