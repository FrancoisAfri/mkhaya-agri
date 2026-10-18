<?php

namespace Database\Factories\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PettyCashFloatFactory extends Factory
{
    protected $model = PettyCashFloat::class;

    public function definition(): array
    {
        return [
            'uuid'                    => (string) Str::uuid(),
            'name'                    => fake()->randomElement(['Operations', 'Finance', 'HR', 'Admin', 'Reception']) . ' Petty Cash',
            'description'             => fake()->sentence(),
            'custodian_id'            => User::factory(),
            'department_id'           => null,
            'opening_balance'         => 5000.00,
            'current_balance'         => 5000.00,
            'float_limit'             => 5000.00,
            'replenishment_threshold' => 1000.00,
            'currency'                => 'ZAR',
            'status'                  => 'active',
            'notes'                   => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }
}
