<?php

namespace App\Modules\HR\PreBoarding\Models;

use App\Core\User\Models\User;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskStatus;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskType;
use Database\Factories\PreBoarding\PreBoardingTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreBoardingTask extends Model
{
    use HasFactory;

    protected $table = 'pre_boarding_tasks';

    protected $fillable = [
        'pre_boarding_plan_id',
        'type',
        'title',
        'description',
        'due_date',
        'sort_order',
        'owner_role',
        'assigned_to',
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
        'type'         => PreBoardingTaskType::class,
        'status'       => PreBoardingTaskStatus::class,
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function newFactory(): PreBoardingTaskFactory
    {
        return PreBoardingTaskFactory::new();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PreBoardingPlan::class, 'pre_boarding_plan_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isComplete(): bool
    {
        return $this->status->isTerminal();
    }
}
