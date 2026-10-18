<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M9 — Policy Violation Log
 * 
 * Records when asset actions violate configured policies.
 * Integrates with PolicyOS engine.
 */
class AssetPolicyViolation extends Model
{
    protected $table = 'asset_policy_violations';

    protected $fillable = [
        'uuid',
        'asset_id',
        'user_id',
        'policy_rule_id',            // FK to PolicyOS rules table
        'violation_type',            // assignment_limit | value_threshold | unauthorized_transfer | missing_approval | retention_breach
        'severity',                  // low | medium | high | critical
        'detected_at',
        'description',
        'policy_rule_details',       // JSON snapshot of rule
        'action_blocked',            // Was the action prevented?
        'status',                    // open | acknowledged | resolved | exception_granted
        'resolved_by_user_id',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'detected_at'          => 'datetime',
        'resolved_at'          => 'datetime',
        'policy_rule_details'  => 'array',
        'action_blocked'       => 'boolean',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    // Helpers
    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'exception_granted']);
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function wasBlocked(): bool
    {
        return $this->action_blocked;
    }
}
