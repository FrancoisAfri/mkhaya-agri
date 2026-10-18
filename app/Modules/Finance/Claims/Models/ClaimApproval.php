<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ClaimApproval extends Model
{
    use LogsActivity;
    protected $table = 'claim_approvals';

    protected $fillable = [
        'claim_id', 'approver_id', 'level', 'action',
        'notes', 'rejection_reason', 'actioned_at',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Finance')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            'approved'  => 'success',
            'rejected'  => 'error',
            'returned'  => 'warning',
            'escalated' => 'info',
            default     => 'grey',
        };
    }
}
