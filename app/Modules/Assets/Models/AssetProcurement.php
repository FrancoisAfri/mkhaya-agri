<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * M11 — Asset Procurement
 * 
 * Tracks procurement from request to capitalisation.
 */
class AssetProcurement extends Model
{
    protected $table = 'asset_procurements';

    protected $fillable = [
        'uuid',
        'procurement_number',
        'requested_by_user_id',
        'asset_category_id',
        'description',
        'justification',
        'quantity',
        'estimated_unit_cost',
        'estimated_total_cost',
        'budget_code',
        'supplier_id',
        'purchase_order_id',
        'status',                    // requested | approved | ordered | received | capitalised | cancelled
        'requested_date',
        'approved_date',
        'ordered_date',
        'received_date',
        'capitalised_date',
    ];

    protected $casts = [
        'quantity'              => 'integer',
        'estimated_unit_cost'   => 'decimal:2',
        'estimated_total_cost'  => 'decimal:2',
        'requested_date'        => 'date',
        'approved_date'         => 'date',
        'ordered_date'          => 'date',
        'received_date'         => 'date',
        'capitalised_date'      => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
            if (!$model->procurement_number) {
                $model->procurement_number = 'PROC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    // Relationships
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AssetSupplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(AssetPurchaseOrder::class, 'purchase_order_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Assets::class, 'procurement_id');
    }
}
