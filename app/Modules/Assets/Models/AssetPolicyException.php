<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M9 — Policy Exception Request
 * 
 * Formal request to override a policy rule for specific asset action.
 * Requires approval before action can proceed.
 */
class AssetPolicyException extends Model
{
    protected $table = 'asset_policy_exceptions';

    protected $fillable = [
        'uuid',
        'asset_id',
        'requested_by_user_id',
        'policy_rule_id',
        'exception_reason',
        'business_justification',
        'requested_at',
        'valid_from',
        'valid_until',
        'status',                    // pending | approved | rejected | expired
        'approved_by_user_id',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'valid_from'   => 'date',
        'valid_until'  => 'date',
        'approved_at'  => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // Helpers
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isActive(): bool
    {
        return $this->status === 'approved' 
            && $this->valid_from <= now() 
            && $this->valid_until >= now();
    }

    public function isExpired(): bool
    {
        return $this->status === 'approved' && $this->valid_until < now();
    }
}
