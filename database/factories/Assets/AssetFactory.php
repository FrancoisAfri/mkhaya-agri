<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Assets::class;

    public function definition(): array
    {
        return [
            'name'                    => fake()->words(3, true),
            'description'             => fake()->sentence(),
            'serial_number'           => strtoupper(fake()->unique()->bothify('SN-########')),
            'asset_tag'               => strtoupper(fake()->unique()->bothify('TAG-####')),
            'asset_number'            => strtoupper(fake()->unique()->numerify('AST-#####')),
            'model_number'            => fake()->bothify('MODEL-???-###'),
            'make_number'             => fake()->company(),
            'asset_type_id'           => null,
            'asset_category_id'       => null,
            'location_id'             => null,
            'purchase_cost'           => fake()->randomFloat(2, 500, 250000),
            'price'                   => fake()->randomFloat(2, 500, 250000),
            'purchase_date'           => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'warranty_expiry'         => fake()->dateTimeBetween('+1 month', '+3 years')->format('Y-m-d'),
            'condition'               => fake()->randomElement(['new', 'good', 'fair', 'poor']),
            'asset_status'            => 'unallocated',
            'status'                  => 1,
            'retention_until'         => now()->addYears(7)->toDateString(),
            'next_impairment_review_at' => now()->addYear()->toDateString(),
            'user_id'                 => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(['asset_status' => 'active']);
    }

    public function unallocated(): static
    {
        return $this->state(['asset_status' => 'unallocated']);
    }

    public function underMaintenance(): static
    {
        return $this->state(['asset_status' => 'under_maintenance']);
    }

    public function disposed(): static
    {
        return $this->state(['asset_status' => 'disposed']);
    }

    public function inCondition(string $condition): static
    {
        return $this->state(['condition' => $condition]);
    }
}
