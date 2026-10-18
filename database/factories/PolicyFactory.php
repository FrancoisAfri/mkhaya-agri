<?php

namespace Database\Factories;

use App\Core\User\Models\User;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'document' => null,
            'status' => fake()->randomElement(['draft', 'active', 'under_review']),
            'date' => now()->timestamp,
            'category_id' => PolicyCategory::query()->value('id')
                ?? PolicyCategory::create([
                    'name' => 'General',
                    'description' => 'Default category',
                    'status' => 1,
                ])->id,
            'refresh_month' => fake()->numberBetween(1, 12),
            'slug' => fake()->unique()->slug(),
            'current_version_id' => null,
            'owner_id' => User::factory(),
            'department_id' => null,
            'effective_date' => now()->toDateString(),
            'review_date' => now()->addMonths(6)->toDateString(),
            'requires_attestation' => false,
            'tags' => ['policy'],
        ];
    }
}
