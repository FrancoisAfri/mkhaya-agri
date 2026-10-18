<?php

namespace Database\Factories\Fleet;

use App\Modules\Fleet\Models\FleetMaintenance;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FleetMaintenanceFactory extends Factory
{
    protected $model = FleetMaintenance::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'vehicle_id'       => FleetVehicle::factory(),
            'maintenance_type' => fake()->randomElement(['scheduled', 'unscheduled', 'inspection']),
            'category'         => fake()->randomElement(['oil_change', 'tyres', 'brakes', 'electrical', 'bodywork']),
            'description'      => fake()->sentence(),
            'scheduled_date'   => fake()->dateTimeBetween('+1 week', '+3 months')->format('Y-m-d'),
            'completed_date'   => null,
            'cost'             => null,
            'labour_cost'      => null,
            'parts_cost'       => null,
            'service_provider' => fake()->company(),
            'status'           => 'scheduled',
            'priority'         => 'normal',
            'created_by'       => null,
            'updated_by'       => null,
        ];
    }

    public function completed(): static
    {
        $cost = fake()->randomFloat(2, 500, 15000);

        return $this->state([
            'status'         => 'completed',
            'completed_date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'cost'           => $cost,
            'labour_cost'    => $cost * 0.4,
            'parts_cost'     => $cost * 0.6,
            'invoice_number' => strtoupper(fake()->bothify('INV-######')),
        ]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status'         => 'scheduled',
            'scheduled_date' => fake()->dateTimeBetween('-2 months', '-1 day')->format('Y-m-d'),
        ]);
    }

    public function emergency(): static
    {
        return $this->state([
            'maintenance_type' => 'emergency',
            'priority'         => 'emergency',
        ]);
    }

    public function oilChange(): static
    {
        return $this->state([
            'maintenance_type' => 'scheduled',
            'category'         => 'oil_change',
            'description'      => 'Scheduled oil and filter change',
        ]);
    }
}
