<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsPolicyException extends Model
{
    protected $table = 'lms_policy_exceptions';

    protected $fillable = [
        'user_id', 'rule_id', 'policy_exception_id',
        'context_type', 'context_id',
        'reason', 'evidence',
        'valid_from', 'valid_until',
        'status',
        'requested_by', 'approved_by', 'decided_at', 'decision_notes',
    ];

    protected $casts = [
        'valid_from'  => 'date',
        'valid_until' => 'date',
        'decided_at'  => 'datetime',
    ];

    const STATUSES = ['pending', 'approved', 'rejected', 'expired', 'revoked'];
    const CONTEXT_TYPES = ['enrollment', 'assignment', 'certificate', 'mandatory_training'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LmsComplianceRule::class, 'rule_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'approved'
            && $this->valid_from->lte(now())
            && ($this->valid_until === null || $this->valid_until->gte(now()));
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'approved' => 'success',
            'pending'  => 'warning',
            'rejected' => 'error',
            'expired'  => 'grey',
            'revoked'  => 'deep-orange',
            default    => 'grey',
        };
    }
}
