<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M8 — Utilization Tracking
 * 
 * Daily/weekly utilization metrics for productivity analysis.
 */
class AssetUtilizationLog extends Model
{
    protected $table = 'asset_utilization_logs';

    protected $fillable = [
        'uuid',
        'asset_id',
        'log_date',
        'available_hours',
        'utilized_hours',
        'idle_hours',
        'maintenance_hours',
        'utilization_rate',
        'productivity_score',
    ];

    protected $casts = [
        'log_date'           => 'date',
        'available_hours'    => 'decimal:2',
        'utilized_hours'     => 'decimal:2',
        'idle_hours'         => 'decimal:2',
        'maintenance_hours'  => 'decimal:2',
        'utilization_rate'   => 'decimal:2',
        'productivity_score' => 'integer',
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
