<?php

namespace Database\Factories;

use App\Core\User\Models\User;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\PolicyViolation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyViolation>
 */
class PolicyViolationFactory extends Factory
{
    protected $model = PolicyViolation::class;

    public function definition(): array
    {
        return [
            'policy_id' => Policy::factory(),
            'rule_id' => function (array $attributes) {
                return PolicyRule::factory()->create([
                    'policy_id' => $attributes['policy_id'],
                ])->id;
            },
            'clause_id' => null,
            'event_id' => null,
            'user_id' => User::factory(),
            'department_id' => null,
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'outcome' => fake()->randomElement(['blocked', 'warned', 'escalated', 'logged']),
            'event_snapshot' => ['action' => 'test.event', 'meta' => []],
            'explanation' => fake()->sentence(),
            'status' => 'open',
            'investigator_id' => null,
            'resolved_at' => null,
        ];
    }
}
