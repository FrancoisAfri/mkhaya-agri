<?php

namespace Database\Factories\Fleet;

use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetFuelLog;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetFuelLogFactory extends Factory
{
    protected $model = FleetFuelLog::class;

    public function definition(): array
    {
        $litres       = fake()->randomFloat(3, 20, 80);
        $costPerLitre = fake()->randomFloat(4, 21.00, 26.00);
        $totalCost    = round($litres * $costPerLitre, 2);

        return [
            'vehicle_id'           => FleetVehicle::factory(),
            'driver_id'            => null,
            'fleet_card_id'        => null,
            'filled_at'            => fake()->dateTimeBetween('-6 months', 'now'),
            'fuel_type'            => fake()->randomElement(['petrol', 'diesel']),
            'litres'               => $litres,
            'cost_per_litre'       => $costPerLitre,
            'total_cost'           => $totalCost,
            'odometer_reading'     => fake()->numberBetween(10000, 200000),
            'station_name'         => fake()->randomElement(['Shell', 'BP', 'Sasol', 'Engen', 'Total']),
            'station_city'         => fake()->city(),
            'full_tank'            => true,
            'consumption_per_100km'=> fake()->randomFloat(2, 7.0, 14.0),
            'notes'                => null,
            'created_by'           => null,
        ];
    }

    public function forVehicle(FleetVehicle $vehicle): static
    {
        return $this->state(['vehicle_id' => $vehicle->id]);
    }

    public function forDriver(FleetDriver $driver): static
    {
        return $this->state(['driver_id' => $driver->id]);
    }

    public function diesel(): static
    {
        return $this->state(['fuel_type' => 'diesel']);
    }

    public function highConsumption(): static
    {
        return $this->state(['consumption_per_100km' => fake()->randomFloat(2, 18.0, 25.0)]);
    }
}
