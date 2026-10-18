<?php

namespace Database\Factories;

use App\Core\User\Models\User;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyRule>
 */
class PolicyRuleFactory extends Factory
{
    protected $model = PolicyRule::class;

    public function definition(): array
    {
        return [
            'policy_id' => Policy::factory(),
            'clause_id' => null,
            'name' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'natural_language_input' => null,
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'action_on_breach' => fake()->randomElement(['block', 'warn', 'escalate', 'log_only']),
            'requires_exception_approval' => false,
            'active' => true,
            'created_by' => User::factory(),
        ];
    }
}
