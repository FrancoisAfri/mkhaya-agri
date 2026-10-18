<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetIncidentFactory extends Factory
{
    protected $model = AssetIncident::class;

    public function definition(): array
    {
        return [
            'asset_id'    => Assets::factory(),
            'reported_by' => User::factory(),
            'title'       => fake()->sentence(4),
            'description' => fake()->sentence(),
            'severity'    => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'repair_cost' => fake()->randomFloat(2, 0, 25000),
            'is_resolved' => false,
            'occurred_at' => now(),
        ];
    }

    public function resolved(): static
    {
        return $this->state([
            'is_resolved'      => true,
            'resolved_at'      => now(),
            'resolution_notes' => fake()->sentence(),
        ]);
    }

    public function critical(): static
    {
        return $this->state(['severity' => 'critical']);
    }
}
