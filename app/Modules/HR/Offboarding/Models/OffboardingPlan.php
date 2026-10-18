<?php

namespace App\Modules\HR\Offboarding\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Offboarding\Enums\OffboardingStatus;
use App\Modules\HR\Offboarding\Enums\OffboardingTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class OffboardingPlan extends Model
{
    use SoftDeletes;

    protected $table = 'offboarding_plans';

    protected $fillable = [
        'hr_person_id',
        'template_id',
        'termination_type',
        'resignation_date',
        'last_working_day',
        'status',
        'notes',
        'created_by',
        'rehire_eligible',
        'rehire_notes',
        'counteroffer_made',
        'counteroffer_outcome',
    ];

    protected $casts = [
        'status'             => OffboardingStatus::class,
        'resignation_date'   => 'date',
        'last_working_day'   => 'date',
        'rehire_eligible'    => 'boolean',
        'counteroffer_made'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OffboardingTemplate::class, 'template_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OffboardingTask::class, 'plan_id')->orderBy('sort_order');
    }

    public function exitInterview(): HasOne
    {
        return $this->hasOne(OffboardingExitInterview::class, 'plan_id');
    }

    public function clearances(): HasMany
    {
        return $this->hasMany(OffboardingClearance::class, 'plan_id');
    }

    public function assetReturns(): HasMany
    {
        return $this->hasMany(OffboardingAssetReturn::class, 'plan_id');
    }

    public function completionPercentage(): int
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $total = $tasks->count();
        if ($total === 0) return 0;

        $terminals = [OffboardingTaskStatus::Completed->value, OffboardingTaskStatus::Skipped->value];
        $done = $tasks->whereIn('status', $terminals)->count();

        return (int) round(($done / $total) * 100);
    }

    public function isFullyComplete(): bool
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        if ($tasks->isEmpty()) return false;

        $terminals = [OffboardingTaskStatus::Completed->value, OffboardingTaskStatus::Skipped->value];

        return $tasks->whereNotIn('status', $terminals)->isEmpty();
    }
}
