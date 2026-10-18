<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryPolicyException extends Model
{
    protected $table = 'disciplinary_policy_exceptions';

    protected $fillable = [
        'violation_id', 'case_id', 'hearing_id',
        'reason', 'justification', 'risk_acknowledged',
        'requested_by', 'reviewed_by',
        'status', 'reviewed_at', 'approved_until', 'review_note',
    ];

    protected $casts = [
        'reviewed_at'   => 'datetime',
        'approved_until'=> 'date',
    ];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryPolicyViolation::class, 'violation_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'approved' => 'success',
            'rejected' => 'error',
            default    => 'warning',
        };
    }

    public function isActive(): bool
    {
        return $this->status === 'approved'
            && ($this->approved_until === null || $this->approved_until->isFuture());
    }
}
