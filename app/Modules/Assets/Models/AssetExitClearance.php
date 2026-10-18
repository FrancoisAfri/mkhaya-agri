<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetExitClearanceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * M5 — Exit Clearance
 *
 * Generated when employee terminates — tracks return of all assigned assets.
 * Blocks final payroll until all assets returned or liabilities settled.
 */
class AssetExitClearance extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetExitClearanceFactory::new();
    }

    protected $table = 'asset_exit_clearances';

    protected $fillable = [
        'uuid',
        'user_id',
        'employee_id',
        'initiated_by',
        'target_exit_date',
        'termination_date',
        'clearance_deadline',
        'total_assets_assigned',
        'total_assets_returned',
        'total_assets_missing',
        'total_liability_amount',
        'status',
        'cleared_at',
        'completed_at',
        'completed_by_user_id',
        'notes',
        'approval_id',
    ];

    protected $casts = [
        'status'                  => \App\Modules\Assets\Enums\ExitClearanceStatus::class,
        'target_exit_date'        => 'date',
        'termination_date'        => 'date',
        'clearance_deadline'      => 'date',
        'total_assets_assigned'   => 'integer',
        'total_assets_returned'   => 'integer',
        'total_assets_missing'    => 'integer',
        'total_liability_amount'  => 'decimal:2',
        'cleared_at'              => 'datetime',
        'completed_at'            => 'datetime',
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

     /**
     * Route model binding via uuid au lieu de id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }


    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetExitClearanceItem::class, 'clearance_id');
    }

    public function pendingItems(): HasMany
    {
        return $this->hasMany(AssetExitClearanceItem::class, 'clearance_id')->where('resolution', 'pending');
    }

    public function liabilities(): HasMany
    {
        return $this->hasMany(AssetLiability::class, 'exit_clearance_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    // Helpers
    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'completed' && $this->clearance_deadline < now();
    }

    public function hasOutstandingLiabilities(): bool
    {
        return $this->total_liability_amount > 0;
    }

    public function completionPercentage(): float
    {
        if ($this->total_assets_assigned === 0) {
            return 100;
        }

        return ($this->total_assets_returned / $this->total_assets_assigned) * 100;
    }
}
