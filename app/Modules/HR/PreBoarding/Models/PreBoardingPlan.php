<?php

namespace App\Modules\HR\PreBoarding\Models;

use App\Modules\HR\PreBoarding\Enums\PreBoardingStatus;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskStatus;
use Database\Factories\PreBoarding\PreBoardingPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PreBoardingPlan extends Model
{
    use HasFactory;

    protected $table = 'pre_boarding_plans';

    protected $fillable = [
        'hr_person_id',
        'due_date',
        'created_by',
        // Completion is written by the Complete*TaskAction through update(), which honours
        // $fillable — so leaving these out did not fail, it silently discarded them. Marking a task
        // done saved nothing and the plan never advanced.
        'status',
        'completed_at',
        'completed_by',
        // Tenancy. Not fillable meant every create() passing company_id had it silently dropped —
        // including the bulk import — leaving rows that belong to no tenant and which the RLS
        // policy (`company_id = session`) then hides from everyone, including their owner.
        'company_id',
    ];

    protected $casts = [
        'status'       => PreBoardingStatus::class,
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected static function newFactory(): PreBoardingPlanFactory
    {
        return PreBoardingPlanFactory::new();
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(PreBoardingTask::class)->orderBy('sort_order');
    }

    public function isFullyComplete(): bool
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        if ($tasks->isEmpty()) return false;

        // Compared as enum cases, not as their string values. `status` is cast to PreBoardingTaskStatus,
        // so the collection holds enum instances — and an enum is never loosely equal to a string
        // in PHP 8. Against `->value` strings nothing ever matched, every task looked unfinished,
        // and a plan could never reach Completed however many tasks were ticked off.
        $terminals = [PreBoardingTaskStatus::Completed, PreBoardingTaskStatus::Skipped];

        return $tasks->reject(fn ($task) => in_array($task->status, $terminals, true))->isEmpty();
    }
}
