<?php

namespace App\Modules\HR\Onboarding\Models;

use App\Modules\HR\Onboarding\Enums\OnboardingTaskStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use Database\Factories\Onboarding\OnboardingTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = 'onboarding_tasks';

    protected $fillable = [
        'onboarding_plan_id',
        'type',
        'title',
        'description',
        'due_date',
        'assigned_to',
        'completed_by',
        'sort_order',
        // Completion is written by the Complete*TaskAction through update(), which honours
        // $fillable — so leaving these out did not fail, it silently discarded them. Marking a task
        // done saved nothing and the plan never advanced.
        'status',
        'completed_at',
        // Tenancy. Not fillable meant every create() passing company_id had it silently dropped —
        // including the bulk import — leaving rows that belong to no tenant and which the RLS
        // policy (`company_id = session`) then hides from everyone, including their owner.
        'company_id',
    ];

    protected $casts = [
        'type'         => OnboardingTaskType::class,
        'status'       => OnboardingTaskStatus::class,
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function newFactory(): OnboardingTaskFactory
    {
        return OnboardingTaskFactory::new();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OnboardingPlan::class, 'onboarding_plan_id');
    }

    public function isComplete(): bool
    {
        return $this->status->isTerminal();
    }
}
