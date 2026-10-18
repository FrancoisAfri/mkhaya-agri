<?php

namespace App\Modules\HR\Onboarding\Services;

use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskType;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Build a pre-boarding plan from an onboarding template.
 *
 * Pre-boarding had no templates of its own, so every plan was assembled by hand. Rather than add a
 * second catalogue to maintain in parallel, this splits the existing one: a template task with a
 * *negative* day offset is by definition work that must happen before the person arrives, which is
 * exactly what pre-boarding is. 298 of the 814 catalogue tasks are already on that side of day zero.
 *
 * The alternative — a separate pre-boarding catalogue — would mean two lists that drift, and the
 * drift would show up as a contract chased twice or not at all.
 */
class TemplateSplitService
{
    /**
     * The two enums do not have the same cases, so the onboarding vocabulary is mapped onto the
     * pre-boarding one. Anything without a natural counterpart becomes form completion, which is
     * the least wrong of the available answers for "something to do before you start".
     */
    private const TYPE_MAP = [
        'reading' => PreBoardingTaskType::DocumentSubmission,
        'system_setup' => PreBoardingTaskType::SystemAccess,
        'introduction' => PreBoardingTaskType::WelcomePack,
        'policy_acknowledgement' => PreBoardingTaskType::FormCompletion,
        'meeting' => PreBoardingTaskType::FormCompletion,
        'training' => PreBoardingTaskType::FormCompletion,
    ];

    /** Tasks before day zero, as pre-boarding. */
    public function preBoardingTasks(OnboardingTemplate $template, Carbon $startDate): array
    {
        return $template->loadMissing('templateTasks')->templateTasks
            ->filter(fn ($task) => $task->day_offset < 0)
            ->sortBy('day_offset')
            ->values()
            ->map(fn ($task, $index) => [
                'type' => $this->mapType($task->type),
                'title' => $task->title,
                'description' => $task->description,
                // Still relative to the start date, so a plan built six weeks early is dated from
                // the person's first day rather than from when somebody happened to create it.
                'due_date' => $startDate->copy()->addDays($task->day_offset)->toDateString(),
                'owner_role' => $task->owner_type,
                'sort_order' => $index,
            ])->all();
    }

    public function createPreBoardingPlan(
        OnboardingTemplate $template,
        int $hrPersonId,
        Carbon $startDate,
        int $createdBy,
    ): PreBoardingPlan {
        return DB::transaction(function () use ($template, $hrPersonId, $startDate, $createdBy) {
            $companyId = app(CurrentCompany::class)->id();
            $tasks = $this->preBoardingTasks($template, $startDate);

            $plan = PreBoardingPlan::create([
                'hr_person_id' => $hrPersonId,
                // The plan is due when the person arrives: everything in it exists to be finished
                // before then.
                'due_date' => $startDate->toDateString(),
                'status' => 'pending',
                'created_by' => $createdBy,
                'company_id' => $companyId,
            ]);

            foreach ($tasks as $task) {
                PreBoardingTask::create($task + [
                    'pre_boarding_plan_id' => $plan->id,
                    'status' => 'pending',
                    'company_id' => $companyId,
                ]);
            }

            return $plan->fresh();
        });
    }

    private function mapType(OnboardingTaskType|string|null $type): string
    {
        $value = $type instanceof OnboardingTaskType ? $type->value : (string) $type;

        return (self::TYPE_MAP[$value] ?? PreBoardingTaskType::FormCompletion)->value;
    }
}
