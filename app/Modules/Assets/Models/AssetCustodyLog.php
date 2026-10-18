<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetCustodyLogFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCustodyLog extends Model
{
    use Uuids, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetCustodyLogFactory::new();
    }

    protected $table = 'asset_custody_logs';

    public $timestamps = true;

    protected $fillable = [
        'uuid',
        'asset_id',
        'assignment_id',
        'action',
        'from_user_id',
        'to_user_id',
        'from_location_id',
        'to_location_id',
        'performed_by',
        'notes',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
            }
        });

        // Immutable — append-only audit ledger
        static::updating(fn() => throw new \LogicException('AssetCustodyLog records are immutable.'));
        static::deleting(fn() => throw new \LogicException('AssetCustodyLog records cannot be deleted.'));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssetAssignment::class, 'assignment_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
