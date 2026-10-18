<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetIncidentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M2 — Asset Incident Tracking
 * 
 * Records damage, loss, theft, malfunction events.
 * Feeds into risk scoring and digital twin health metrics.
 */
class AssetIncident extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetIncidentFactory::new();
    }

    protected $table = 'asset_incidents';

    protected $fillable = [
        'uuid',
        'asset_id',
        'digital_twin_id',
        'reported_by',
        'title',
        'description',
        'severity',
        'repair_cost',
        'is_resolved',
        'resolved_at',
        'resolution_notes',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at'  => 'datetime',
        'resolved_at'  => 'datetime',
        'repair_cost'  => 'decimal:2',
        'is_resolved'  => 'boolean',
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

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    // Helpers
    public function isResolved(): bool
    {
        return !is_null($this->resolved_at);
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }
}
