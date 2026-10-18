<?php

namespace Database\Factories\Fleet;

use App\Modules\Fleet\Models\FleetCard;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FleetCardFactory extends Factory
{
    protected $model = FleetCard::class;

    public function definition(): array
    {
        return [
            'uuid'                => (string) Str::uuid(),
            'card_number_last4'   => (string) fake()->numerify('####'),
            'card_token'          => Str::random(32),
            'card_type'           => 'fuel',
            'provider'            => fake()->randomElement(['Shell', 'BP', 'Sasol', 'Engen']),
            'vehicle_id'          => FleetVehicle::factory(),
            'driver_id'           => null,
            'expiry_month'        => fake()->numberBetween(1, 12),
            'expiry_year'         => fake()->numberBetween(date('Y') + 1, date('Y') + 5),
            'status'              => 'active',
            'daily_limit'         => 2000.00,
            'monthly_limit'       => 15000.00,
            'current_month_spend' => 0.00,
            'notes'               => null,
            'created_by'          => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function blocked(): static
    {
        return $this->state(['status' => 'blocked']);
    }

    public function fuelCard(): static
    {
        return $this->state(['card_type' => 'fuel']);
    }

    public function tollCard(): static
    {
        return $this->state(['card_type' => 'toll']);
    }
}
