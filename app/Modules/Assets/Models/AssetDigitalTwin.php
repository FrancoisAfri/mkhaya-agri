<?php

namespace App\Modules\Assets\Models;

use Database\Factories\Assets\AssetDigitalTwinFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M2 — Digital Twin
 * 
 * Real-time digital representation of physical asset state.
 * Aggregates all movements, incidents, performance logs into a single queryable profile.
 */
class AssetDigitalTwin extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetDigitalTwinFactory::new();
    }

    protected $table = 'asset_digital_twins';

    protected $fillable = [
        'uuid',
        'asset_id',
        'condition',
        'current_location_id',
        'operating_hours',
        'odometer_reading',
        'last_maintenance_at',
        'next_maintenance_due',
        'health_score',
        'utilization_rate',
        'sensor_data',
        'synced_at',
    ];

    protected $casts = [
        'operating_hours'      => 'float',
        'odometer_reading'     => 'float',
        'health_score'         => 'float',
        'utilization_rate'     => 'float',
        'last_maintenance_at'  => 'datetime',
        'next_maintenance_due' => 'date',
        'synced_at'            => 'datetime',
        'sensor_data'          => 'array',
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
    public function isHealthy(): bool
    {
        return $this->health_score >= 70;
    }

    public function isAtRisk(): bool
    {
        return in_array($this->risk_level, ['high', 'critical']);
    }

    public function needsAttention(): bool
    {
        return $this->health_score < 50 || $this->risk_level === 'critical';
    }
}
