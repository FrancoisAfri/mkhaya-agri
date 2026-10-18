<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementAssetLink extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_asset_links';

    protected $fillable = [
        'purchase_order_id', 'purchase_order_item_id', 'asset_id',
        'asset_ref', 'name', 'value',
    ];

    protected $casts = ['value' => 'decimal:2'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
