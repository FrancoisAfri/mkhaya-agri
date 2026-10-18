<?php

namespace Database\Factories\Recruitment;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobFactory extends Factory
{
    protected $model = Job::class;

    public function definition(): array
    {
        return [
            'title'           => fake()->jobTitle(),
            'reference_number'=> strtoupper(fake()->unique()->bothify('JOB-####')),
            'company_unit_id' => null,
            'location'        => fake()->city(),
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'contract']),
            'description'     => fake()->paragraph(),
            'requirements'    => fake()->paragraph(),
            'responsibilities'=> fake()->paragraph(),
            'salary_min'      => 20000,
            'salary_max'      => 50000,
            'currency'        => 'ZAR',
            'is_active'       => true,
            'posted_at'       => now(),
            'closes_at'       => now()->addDays(30),
            'created_by'      => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function closed(): static
    {
        return $this->state(['closes_at' => now()->subDay(), 'is_active' => false]);
    }
}
