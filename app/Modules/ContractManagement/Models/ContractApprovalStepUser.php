<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContractApprovalStepUser extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_approval_step_users';

    protected $fillable = ['uuid', 'step_id', 'user_id'];

    protected static function booted(): void
    {
        static::creating(function (ContractApprovalStepUser $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ContractApprovalStep::class, 'step_id');
    }
}
