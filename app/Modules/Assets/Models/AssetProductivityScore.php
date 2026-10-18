<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M8 — Productivity Scoring
 * 
 * Calculated productivity metrics for asset performance benchmarking.
 */
class AssetProductivityScore extends Model
{
    protected $table = 'asset_productivity_scores';

    protected $fillable = [
        'uuid',
        'asset_id',
        'score_date',
        'productivity_score',        // 0-100
        'utilization_score',         // 0-100
        'efficiency_score',          // 0-100
        'reliability_score',         // 0-100
        'output_per_hour',
        'cost_per_output_unit',
        'benchmark_comparison',      // above | at | below
    ];

    protected $casts = [
        'score_date'            => 'date',
        'productivity_score'    => 'integer',
        'utilization_score'     => 'integer',
        'efficiency_score'      => 'integer',
        'reliability_score'     => 'integer',
        'output_per_hour'       => 'decimal:2',
        'cost_per_output_unit'  => 'decimal:2',
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
