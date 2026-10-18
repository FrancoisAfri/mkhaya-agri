<?php

namespace Database\Factories\Fleet;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetDriver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FleetDriverFactory extends Factory
{
    protected $model = FleetDriver::class;

    public function definition(): array
    {
        return [
            'uuid'          => (string) Str::uuid(),
            'user_id'       => User::factory(),
            'licence_number'=> strtoupper(fake()->unique()->bothify('??########')),
            'licence_type'  => fake()->randomElement(['B', 'C', 'C1', 'EB']),
            'licence_expiry'=> fake()->dateTimeBetween('+6 months', '+4 years')->format('Y-m-d'),
            'pdp_number'    => null,
            'pdp_expiry'    => null,
            'status'        => 'active',
            'can_drive'     => true,
            'total_trips'   => 0,
            'total_km'      => 0,
            'incidents_count'=> 0,
            'created_by'    => null,
            'updated_by'    => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active', 'can_drive' => true]);
    }

    public function suspended(): static
    {
        return $this->state([
            'status'             => 'suspended',
            'can_drive'          => false,
            'restriction_reason' => 'Suspended pending investigation',
        ]);
    }

    public function expiredLicence(): static
    {
        return $this->state([
            'licence_expiry' => fake()->dateTimeBetween('-2 years', '-1 day')->format('Y-m-d'),
        ]);
    }

    public function withPdp(): static
    {
        return $this->state([
            'pdp_number' => strtoupper(fake()->bothify('PDP-######')),
            'pdp_expiry' => fake()->dateTimeBetween('+6 months', '+2 years')->format('Y-m-d'),
        ]);
    }
}
