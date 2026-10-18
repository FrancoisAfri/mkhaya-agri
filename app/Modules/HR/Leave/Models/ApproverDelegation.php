<?php

namespace App\Modules\HR\Leave\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ApproverDelegation extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveApproverDelegation')
            ->logOnly(['delegator_id', 'delegate_id', 'start_date', 'end_date', 'reason', 'is_active', 'revoked_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Approver delegation #{$this->id} created",
                'updated' => $this->revoked_at && $this->isDirty('revoked_at')
                    ? "Approver delegation #{$this->id} revoked"
                    : "Approver delegation #{$this->id} updated",
                'deleted' => "Approver delegation #{$this->id} deleted",
                default   => "Approver delegation #{$this->id}: {$e}",
            });
    }
    protected $table = 'leave_delegations';

    protected $fillable = [
        'delegator_id', 'delegate_id',
        'start_date', 'end_date',
        'reason', 'is_active',
        'approval_status', 'decline_reason',
        'revoked_at', 'revoked_by', 'created_by',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'revoked_at'  => 'datetime',
        'is_active'   => 'boolean',
    ];

    public function scopePendingApproval($query)
    {
        return $query->where('approval_status', 'pending');
    }

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

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active delegation that covers today for a given delegator. */
    public function scopeActiveForUser($query, int $userId)
    {
        return $query->where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date',   '>=', now()->toDateString());
    }
}
