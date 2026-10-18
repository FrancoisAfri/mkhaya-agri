<?php

namespace Database\Factories\PreBoarding;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\PreBoarding\Enums\HrPersonStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrPersonFactory extends Factory
{
    protected $model = HrPerson::class;

    public function definition(): array
    {
        $contract = Contract::factory()->signed()->create();

        return [
            'candidate_id' => $contract->candidate_id,
            'contract_id'  => $contract->id,
            'created_by'   => User::factory(),
        ];
    }

    public function onboarding(): static
    {
        return $this->state(['status' => HrPersonStatus::Onboarding->value]);
    }

    public function active(): static
    {
        return $this->state([
            'status'     => HrPersonStatus::Active->value,
            'started_on' => now()->subDays(30)->toDateString(),
        ]);
    }

    public function withEmployeeNumber(): static
    {
        return $this->state(['employee_number' => 'EMP-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)]);
    }
}
