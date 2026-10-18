<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimApprovalDelegation extends Model
{
    protected $table = 'claim_approval_delegations';

    protected $fillable = [
        'delegator_id', 'delegate_id',
        'start_date', 'end_date',
        'reason', 'is_active',
        'revoked_at', 'revoked_by', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'revoked_at' => 'datetime',
        'is_active'  => 'boolean',
    ];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** Active delegation covering today for a given delegator. */
    public function scopeActiveForUser($query, int $userId)
    {
        return $query->where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date',   '>=', now()->toDateString());
    }
}
