<?php

namespace Database\Factories\PreBoarding;

use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskStatus;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskType;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class PreBoardingTaskFactory extends Factory
{
    protected $model = PreBoardingTask::class;

    public function definition(): array
    {
        return [
            'pre_boarding_plan_id' => PreBoardingPlan::factory(),
            'type'                 => $this->faker->randomElement(PreBoardingTaskType::cases())->value,
            'title'                => $this->faker->sentence(4),
            'sort_order'           => 0,
            // The column defaults to 'pending' in the database, but Eloquent does not read defaults
            // back after an insert — so a freshly-made model carried a null status in memory and
            // any code doing $task->status->isTerminal() fatalled on it.
            'status'               => PreBoardingTaskStatus::Pending->value,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status'       => PreBoardingTaskStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state([
            'status'       => PreBoardingTaskStatus::Skipped->value,
            'completed_at' => now(),
        ]);
    }
}
