<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M6 — Asset Behavior Log
 * 
 * Tracks usage patterns for anomaly detection.
 * Feeds into misuse detection algorithms.
 */
class AssetBehaviorLog extends Model
{
    protected $table = 'asset_behavior_logs';

    protected $fillable = [
        'uuid',
        'asset_id',
        'user_id',
        'action_type',               // access | usage | transfer | modification | checkout | checkin
        'action_timestamp',
        'location_id',
        'ip_address',
        'device_info',
        'duration_minutes',
        'metadata',                  // JSON — extensible
    ];

    protected $casts = [
        'action_timestamp'   => 'datetime',
        'duration_minutes'   => 'integer',
        'metadata'           => 'array',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }
}
