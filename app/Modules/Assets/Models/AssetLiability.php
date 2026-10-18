<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M5 — Asset Liability
 * 
 * Financial liability for missing/damaged assets during exit clearance.
 * Integrates with payroll for deduction processing.
 */
class AssetLiability extends Model
{
    protected $table = 'asset_liabilities';

    protected $fillable = [
        'uuid',
        'exit_clearance_id',
        'asset_id',
        'employee_id',
        'liability_type',            // missing | damaged | lost | stolen
        'asset_book_value',
        'liability_amount',
        'settlement_status',         // pending | deducted | waived | paid
        'settlement_date',
        'settlement_method',         // payroll_deduction | cash | waived
        'approved_by_user_id',
        'notes',
    ];

    protected $casts = [
        'asset_book_value'   => 'decimal:2',
        'liability_amount'   => 'decimal:2',
        'settlement_date'    => 'date',
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
        return $this->belongsTo(AssetExitClearance::class, 'exit_clearance_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // Helpers
    public function isSettled(): bool
    {
        return in_array($this->settlement_status, ['deducted', 'waived', 'paid']);
    }

    public function isPending(): bool
    {
        return $this->settlement_status === 'pending';
    }
}
