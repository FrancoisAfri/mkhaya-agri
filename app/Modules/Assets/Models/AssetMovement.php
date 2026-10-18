<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetMovementFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M2 — Asset Movement Tracking
 * 
 * Tracks physical movement of assets between locations.
 * Feeds into digital twin for movement history and location analytics.
 */
class AssetMovement extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetMovementFactory::new();
    }

    protected $table = 'asset_movements';

    protected $fillable = [
        'uuid',
        'asset_id',
        'digital_twin_id',
        'from_location_id',
        'to_location_id',
        'moved_by',
        'reason',
        'gps_lat',
        'gps_lng',
        'moved_at',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
        'gps_lat'  => 'decimal:8',
        'gps_lng'  => 'decimal:8',
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

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'to_location_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
