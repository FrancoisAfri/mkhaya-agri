<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M11 — Supplier Performance Tracking
 * 
 * Tracks delivery time, quality, pricing for supplier evaluation.
 */
class AssetSupplierPerformance extends Model
{
    protected $table = 'asset_supplier_performance';

    protected $fillable = [
        'uuid',
        'supplier_id',
        'purchase_order_id',
        'evaluation_date',
        'delivery_timeliness_score',  // 0-100
        'quality_score',              // 0-100
        'pricing_competitiveness',    // 0-100
        'communication_score',        // 0-100
        'overall_score',              // 0-100
        'comments',
    ];

    protected $casts = [
        'evaluation_date'             => 'date',
        'delivery_timeliness_score'   => 'integer',
        'quality_score'               => 'integer',
        'pricing_competitiveness'     => 'integer',
        'communication_score'         => 'integer',
        'overall_score'               => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AssetSupplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(AssetPurchaseOrder::class, 'purchase_order_id');
    }
}
