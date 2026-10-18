<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractStepStatus extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_step_statuses';

    protected $fillable = [
        'contract_id',
        'step_id',
        'step_type',
        'status',
        'comment',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ContractApprovalStep::class, 'step_id');
    }
}
