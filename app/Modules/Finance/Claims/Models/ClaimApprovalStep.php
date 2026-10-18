<?php

namespace App\Modules\Finance\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimApprovalStep extends Model
{
    protected $table = 'claim_approval_steps';

    protected $fillable = [
        'step_number', 'step_label', 'approver_type', 'role_name', 'employee_id',
        'amount_min', 'amount_max', 'applies_to_travel', 'applies_to_standard', 'is_active',
    ];

    protected $casts = [
        'applies_to_travel'   => 'boolean',
        'applies_to_standard' => 'boolean',
        'is_active'           => 'boolean',
        'amount_min'          => 'decimal:2',
        'amount_max'          => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'employee_id');
    }
}
