<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M6 — Asset Risk Score (Time-series)
 * 
 * Historical risk scoring snapshots for trend analysis.
 * Generated weekly by scheduled job.
 */
class AssetRiskScore extends Model
{
    protected $table = 'asset_risk_scores';

    protected $fillable = [
        'uuid',
        'asset_id',
        'score_date',
        'risk_score',                // 0-100
        'risk_level',                // low | medium | high | critical
        'misuse_score',              // 0-100
        'compliance_score',          // 0-100
        'utilization_score',         // 0-100
        'maintenance_score',         // 0-100
        'incident_score',            // 0-100
        'anomalies_detected',        // JSON array
        'recommendations',           // JSON array
    ];

    protected $casts = [
        'score_date'          => 'date',
        'risk_score'          => 'integer',
        'misuse_score'        => 'integer',
        'compliance_score'    => 'integer',
        'utilization_score'   => 'integer',
        'maintenance_score'   => 'integer',
        'incident_score'      => 'integer',
        'anomalies_detected'  => 'array',
        'recommendations'     => 'array',
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
