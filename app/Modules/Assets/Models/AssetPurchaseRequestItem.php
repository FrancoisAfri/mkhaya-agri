<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPurchaseRequestItem extends Model
{
    protected $table = 'asset_purchase_request_items';

    protected $fillable = [
        'purchase_request_id', 'asset_id', 'asset_number', 'serial_number',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(AssetPurchaseRequest::class, 'purchase_request_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }
}
