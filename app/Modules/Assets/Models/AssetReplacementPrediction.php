<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M3 — Replacement Prediction (AI-driven)
 * 
 * Predicts optimal replacement timing based on TCO, depreciation, maintenance costs.
 * Helps with capital planning and budget forecasting.
 */
class AssetReplacementPrediction extends Model
{
    protected $table = 'asset_replacement_predictions';

    protected $fillable = [
        'uuid',
        'asset_id',
        'prediction_date',
        'recommended_replacement_date',
        'confidence_score',                // 0-100
        'current_book_value',
        'estimated_replacement_cost',
        'total_maintenance_cost_ytd',
        'maintenance_cost_trend',          // increasing | stable | decreasing
        'roi_if_replaced',                 // Projected ROI
        'roi_if_kept',
        'reasoning',                       // Text explanation
        'model_version',
        'is_acknowledged',
        'acknowledged_by_user_id',
        'acknowledged_at',
    ];

    protected $casts = [
        'prediction_date'              => 'date',
        'recommended_replacement_date' => 'date',
        'confidence_score'             => 'integer',
        'current_book_value'           => 'decimal:2',
        'estimated_replacement_cost'   => 'decimal:2',
        'total_maintenance_cost_ytd'   => 'decimal:2',
        'roi_if_replaced'              => 'decimal:2',
        'roi_if_kept'                  => 'decimal:2',
        'is_acknowledged'              => 'boolean',
        'acknowledged_at'              => 'datetime',
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
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    // Helpers
    public function shouldReplaceNow(): bool
    {
        return $this->roi_if_replaced > $this->roi_if_kept && $this->confidence_score >= 70;
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence_score >= 75;
    }
}
