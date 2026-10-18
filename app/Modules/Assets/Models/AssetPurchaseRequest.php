<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\PurchaseRequestPriority;
use App\Modules\Assets\Enums\PurchaseRequestStatus;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetPurchaseRequest extends Model
{
    use Uuids, SoftDeletes;

    protected $table = 'asset_purchase_requests';

    protected $fillable = [
        'uuid', 'request_number', 'requested_by', 'item_description',
        'asset_category_id', 'quantity', 'estimated_unit_cost', 'estimated_total_cost',
        'justification', 'priority', 'status', 'approval_id', 'vendor_name',
        'vendor_reference', 'actual_unit_cost', 'actual_total_cost',
        'expected_delivery_date', 'delivered_at', 'delivery_notes', 'submitted_at',
    ];

    protected $casts = [
        'estimated_unit_cost'  => 'decimal:2',
        'estimated_total_cost' => 'decimal:2',
        'actual_unit_cost'     => 'decimal:2',
        'actual_total_cost'    => 'decimal:2',
        'expected_delivery_date' => 'date',
        'delivered_at'         => 'date',
        'submitted_at'         => 'datetime',
        'status'               => PurchaseRequestStatus::class,
        'priority'             => PurchaseRequestPriority::class,
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AssetApproval::class, 'approval_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetPurchaseRequestItem::class, 'purchase_request_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
