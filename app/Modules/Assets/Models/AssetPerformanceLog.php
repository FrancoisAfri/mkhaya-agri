<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M2 — Asset Performance Logging
 * 
 * Time-series performance metrics for assets.
 * Used for utilization tracking, productivity scoring, and predictive analytics.
 */
class AssetPerformanceLog extends Model
{
    protected $table = 'asset_performance_logs';

    protected $fillable = [
        'uuid',
        'asset_id',
        'digital_twin_id',
        'logged_by',
        'operating_hours_delta',
        'odometer_delta',
        'utilization_rate',
        'downtime_minutes',
        'metrics',
        'logged_at',
    ];

    protected $casts = [
        'operating_hours_delta' => 'decimal:2',
        'odometer_delta'        => 'decimal:2',
        'utilization_rate'      => 'decimal:2',
        'downtime_minutes'      => 'integer',
        'metrics'               => 'array',
        'logged_at'             => 'datetime',
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
}
