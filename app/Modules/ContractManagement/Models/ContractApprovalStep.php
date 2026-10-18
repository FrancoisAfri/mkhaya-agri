<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class ContractApprovalStep extends Model
{
    use BelongsToCompany, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'contract_approval_steps';

    protected $fillable = [
        'uuid',
        'step_number',
        'step_name',
        'max_amount',
        'approval_type',
        'role_id',
        'parent_step_id',
        'status',
    ];

    protected $casts = [
        'step_number' => 'integer',
        'max_amount' => 'float',
        'approval_type' => 'integer',
        'status' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (ContractApprovalStep $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_step_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_step_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(ContractApprovalStepUser::class, 'step_id');
    }
}
