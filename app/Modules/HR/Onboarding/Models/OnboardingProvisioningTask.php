<?php

namespace App\Modules\HR\Onboarding\Models;

use App\Modules\HR\Onboarding\Enums\ProvisioningTaskStatus;
use App\Modules\HR\Onboarding\Enums\ProvisioningTaskType;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OnboardingProvisioningTask extends Model
{
    protected $table = 'onboarding_provisioning_tasks';

    protected $fillable = [
        'hr_person_id',
        'type',
        'title',
        'notes',
        'status',
        'assigned_department',
        'assigned_to',
        'due_date',
        'completed_at',
        'created_by',
        // Tenancy, and the link to the actual asset. Not fillable meant every create() passing
        // company_id had it silently dropped — the same trap that has appeared four times in this
        // module now.
        'company_id',
        'asset_id',
        'asset_assignment_id',
    ];

    protected $casts = [
        'type'         => ProvisioningTaskType::class,
        'status'       => ProvisioningTaskStatus::class,
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to');
    }
}
