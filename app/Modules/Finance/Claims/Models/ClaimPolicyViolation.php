<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimPolicyViolation extends Model
{
    protected $table = 'claim_policy_violations';

    protected $fillable = [
        'claim_id', 'policy_rule', 'violation_type', 'description', 'severity',
        'is_exception_requested', 'exception_approved', 'exception_by',
        'exception_reason', 'exception_at',
    ];

    protected $casts = [
        'is_exception_requested' => 'boolean',
        'exception_approved'     => 'boolean',
        'exception_at'           => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function exceptionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_by');
    }

    public function getSeverityColorAttribute(): string
    {
        return $this->severity === 'block' ? 'error' : 'warning';
    }
}
