<?php

namespace Database\Factories\PreBoarding;

use App\Core\User\Models\User;
use App\Modules\HR\PreBoarding\Enums\PreBoardingStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PreBoardingPlanFactory extends Factory
{
    protected $model = PreBoardingPlan::class;

    public function definition(): array
    {
        return [
            'hr_person_id' => HrPerson::factory(),
            'due_date'     => now()->addDays(7)->toDateString(),
            'created_by'   => User::factory(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => PreBoardingStatus::InProgress->value]);
    }

    public function completed(): static
    {
        return $this->state([
            'status'       => PreBoardingStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }
}
