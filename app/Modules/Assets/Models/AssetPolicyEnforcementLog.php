<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M9 — Policy Enforcement Audit Log
 * 
 * Immutable log of all policy checks performed.
 * Compliance audit trail for SOX/POPIA.
 */
class AssetPolicyEnforcementLog extends Model
{
    protected $table = 'asset_policy_enforcement_logs';

    protected $fillable = [
        'uuid',
        'asset_id',
        'user_id',
        'action_type',               // assignment | transfer | disposal | maintenance
        'policy_rule_id',
        'check_result',              // passed | failed | exception_granted
        'checked_at',
        'policy_snapshot',           // JSON snapshot of rule at check time
        'context',                   // JSON — request context
    ];

    protected $casts = [
        'checked_at'       => 'datetime',
        'policy_snapshot'  => 'array',
        'context'          => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // Prevent updates/deletes — immutable log
        static::updating(fn() => throw new \Exception('AssetPolicyEnforcementLog is immutable'));
        static::deleting(fn() => throw new \Exception('AssetPolicyEnforcementLog cannot be deleted'));
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
}
