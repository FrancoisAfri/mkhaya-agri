<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMaintenanceLog extends Model
{
    use Uuids;

    protected $table = 'asset_maintenance_logs';

    protected $fillable = [
        'uuid',
        'asset_id',
        'schedule_id',
        'performed_by',
        'action_taken',
        'parts_replaced',
        'cost',
        'condition_before',
        'condition_after',
        'hours_taken',
        'performed_at',
    ];

    protected $casts = [
        'cost'         => 'decimal:2',
        'hours_taken'  => 'float',
        'parts_replaced' => 'array',
        'performed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
            }
        });

        static::updating(fn() => throw new \LogicException('AssetMaintenanceLog records are immutable.'));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenanceSchedule::class, 'schedule_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
