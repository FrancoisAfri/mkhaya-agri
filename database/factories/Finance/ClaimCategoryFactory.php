<?php

namespace Database\Factories\Finance;

use App\Modules\Finance\Claims\Models\ClaimCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClaimCategoryFactory extends Factory
{
    protected $model = ClaimCategory::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->companySuffix() . ' ' . fake()->randomElement(['Claims', 'Expenses', 'Reimbursements', 'Allowances']),
            // Required, and unique: categories are referenced by code on claim lines and imports.
            'code'        => strtoupper(fake()->unique()->bothify('CLM-###??')),
            'description' => fake()->sentence(),
            'active'      => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
