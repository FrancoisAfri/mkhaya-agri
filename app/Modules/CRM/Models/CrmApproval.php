<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmApproval extends Model
{
    use LogsActivity;

    protected $table = 'crm_approvals';

    protected $fillable = [
        'uuid', 'deal_id', 'type', 'title', 'context', 'amount', 'requested_by', 'approver_id',
        'status', 'request_note', 'decision_note', 'decided_by', 'decided_at', 'mentioned',
    ];

    protected $casts = [
        'context'    => 'array',
        'mentioned'  => 'array',
        'amount'     => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    const TYPES = [
        'discount' => 'Discount approval',
        'deal'     => 'Deal approval',
        'custom'   => 'Custom approval',
    ];

    const STATUSES = [
        'pending'   => ['label' => 'Pending',  'color' => 'warning'],
        'approved'  => ['label' => 'Approved', 'color' => 'success'],
        'rejected'  => ['label' => 'Rejected', 'color' => 'error'],
        'cancelled' => ['label' => 'Cancelled','color' => 'blue-grey'],
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'deal_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
