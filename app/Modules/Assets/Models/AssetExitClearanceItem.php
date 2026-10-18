<?php

namespace App\Modules\Assets\Models;

use Database\Factories\Assets\AssetExitClearanceItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M5 — Exit Clearance Item
 * 
 * Individual asset in an exit clearance checklist.
 */
class AssetExitClearanceItem extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetExitClearanceItemFactory::new();
    }

    protected $table = 'asset_exit_clearance_items';

    protected $fillable = [
        'uuid',
        'clearance_id',
        'exit_clearance_id',
        'asset_id',
        'assignment_id',
        'resolution',
        'resolved_by',
        'resolved_at',
        'transfer_to_user_id',
        'transfer_to_location_id',
        'waiver_reason',
        'expected_return_date',
        'actual_return_date',
        'return_condition',
        'status',
        'notes',
    ];

    protected $casts = [
        'expected_return_date' => 'date',
        'actual_return_date'   => 'date',
        'resolved_at'          => 'datetime',
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
    public function exitClearance(): BelongsTo
    {
        return $this->belongsTo(AssetExitClearance::class, 'clearance_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssetAssignment::class, 'assignment_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // Helpers
    public function isReturned(): bool
    {
        return $this->status === 'returned';
    }

    public function isMissing(): bool
    {
        return $this->status === 'missing';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->expected_return_date < now();
    }
}
