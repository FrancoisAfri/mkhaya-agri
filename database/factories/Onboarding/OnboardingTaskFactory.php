<?php

namespace Database\Factories\Onboarding;

use App\Modules\HR\Onboarding\Enums\OnboardingTaskStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskType;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class OnboardingTaskFactory extends Factory
{
    protected $model = OnboardingTask::class;

    public function definition(): array
    {
        return [
            'onboarding_plan_id' => OnboardingPlan::factory(),
            'type'               => $this->faker->randomElement(OnboardingTaskType::cases())->value,
            'title'              => $this->faker->sentence(4),
            'sort_order'         => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status'       => OnboardingTaskStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => OnboardingTaskStatus::InProgress->value]);
    }
}
