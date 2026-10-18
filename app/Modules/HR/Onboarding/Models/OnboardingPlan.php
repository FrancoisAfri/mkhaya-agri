<?php

namespace App\Modules\HR\Onboarding\Models;

use App\Modules\HR\Onboarding\Enums\OnboardingStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Database\Factories\Onboarding\OnboardingPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OnboardingPlan extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    protected $table = 'onboarding_plans';

    protected $fillable = [
        'hr_person_id',
        'template_id',
        'start_date',
        'end_date',
        'status',
        'created_by',
        // Completion is written by the Complete*TaskAction through update(), which honours
        // $fillable — so leaving these out did not fail, it silently discarded them. Marking a task
        // done saved nothing and the plan never advanced.
        'completed_at',
        // Tenancy. Not fillable meant every create() passing company_id had it silently dropped —
        // including the bulk import — leaving rows that belong to no tenant and which the RLS
        // policy (`company_id = session`) then hides from everyone, including their owner.
        'company_id',
    ];

    protected $casts = [
        'status'       => OnboardingStatus::class,
        'start_date'   => 'date',
        'end_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Onboarding')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected static function newFactory(): OnboardingPlanFactory
    {
        return OnboardingPlanFactory::new();
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class)->orderBy('sort_order');
    }

    public function completionPercentage(): int
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $total = $tasks->count();
        if ($total === 0) return 0;

        $terminals = [OnboardingTaskStatus::Completed->value, OnboardingTaskStatus::Skipped->value];
        $done = $tasks->whereIn('status', $terminals)->count();

        return (int) round(($done / $total) * 100);
    }

    public function isFullyComplete(): bool
    {
        // Use withTrashed so soft-deleted pending tasks prevent premature completion
        $tasks = $this->tasks()->withTrashed()->get();
        if ($tasks->isEmpty()) return false;

        // Compared as enum cases, not as their string values. `status` is cast to OnboardingTaskStatus,
        // so the collection holds enum instances — and an enum is never loosely equal to a string
        // in PHP 8. Against `->value` strings nothing ever matched, every task looked unfinished,
        // and a plan could never reach Completed however many tasks were ticked off.
        $terminals = [OnboardingTaskStatus::Completed, OnboardingTaskStatus::Skipped];

        return $tasks->reject(fn ($task) => in_array($task->status, $terminals, true))->isEmpty();
    }
}
