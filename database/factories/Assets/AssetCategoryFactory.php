<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetCategoryFactory extends Factory
{
    protected $model = AssetCategory::class;

    public function definition(): array
    {
        return [
            'name'                       => fake()->unique()->words(2, true),
            'description'                => fake()->sentence(),
            'parent_id'                  => null,
            'sars_wear_rate'             => fake()->randomElement([0.3333, 0.2, 0.1, 0.05]),
            'sars_allowance_section'     => null,
            'default_depreciation_method'=> null,
            'default_useful_life_years'  => fake()->randomElement([3, 5, 10, 20]),
            'status'                     => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => 0]);
    }
}
