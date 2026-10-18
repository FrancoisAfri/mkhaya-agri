<?php

namespace App\Modules\Finance\Shared\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceApprovalStep extends Model
{
    protected $fillable = [
        'approval_request_id', 'approver_id', 'level', 'action', 'comments', 'acted_at',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(FinanceApprovalRequest::class, 'approval_request_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
