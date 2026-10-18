<?php

namespace Database\Factories\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Enums\OnboardingStatus;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

class OnboardingPlanFactory extends Factory
{
    protected $model = OnboardingPlan::class;

    public function definition(): array
    {
        $start = now()->addDays(7);

        return [
            'hr_person_id' => HrPerson::factory(),
            'start_date'   => $start->toDateString(),
            'end_date'     => $start->addDays(90)->toDateString(),
            'status'       => OnboardingStatus::Active->value,
            'created_by'   => User::factory(),
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status'       => OnboardingStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }
}
