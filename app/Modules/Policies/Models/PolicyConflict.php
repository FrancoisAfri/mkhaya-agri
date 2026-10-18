<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyConflict extends Model
{
    protected $table = 'policy_conflicts';

    protected $fillable = [
        'policy_a_id',
        'policy_b_id',
        'rule_a_id',
        'rule_b_id',
        'conflict_type',
        'description',
        'status',
        'detected_by',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function policyA(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_a_id');
    }

    public function policyB(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_b_id');
    }

    public function ruleA(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class, 'rule_a_id');
    }

    public function ruleB(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class, 'rule_b_id');
    }

    public function detectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detected_by');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
