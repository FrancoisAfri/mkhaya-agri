<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One component line of a bundle (kit): the bundle item is made up of qty of the component. */
class ItemBundle extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_item_bundles';

    protected $fillable = ['company_id', 'bundle_item_id', 'component_item_id', 'qty'];

    protected $casts = ['qty' => 'decimal:4'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'component_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'bundle_item_id');
    }
}
