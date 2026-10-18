<?php

namespace Database\Factories\Fleet;

use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FleetVehicleFactory extends Factory
{
    protected $model = FleetVehicle::class;

    public function definition(): array
    {
        $makes = ['Toyota', 'Ford', 'Volkswagen', 'Nissan', 'BMW', 'Mercedes', 'Hyundai', 'Kia'];
        $models = ['Hilux', 'Ranger', 'Polo', 'Navara', '3 Series', 'C-Class', 'Tucson', 'Sportage'];

        return [
            'uuid'                => (string) Str::uuid(),
            'registration_number' => strtoupper(fake()->unique()->bothify('??-??-###-GP')),
            'fleet_number'        => strtoupper(fake()->unique()->bothify('FLT-####')),
            'make'                => fake()->randomElement($makes),
            'model'               => fake()->randomElement($models),
            'year'                => fake()->numberBetween(2015, 2024),
            'vin_number'          => strtoupper(fake()->unique()->bothify('???##??##?######')),
            'chassis_number'      => strtoupper(fake()->unique()->bothify('CHS-########')),
            'engine_number'       => strtoupper(fake()->unique()->bothify('ENG-######')),
            'colour'              => fake()->safeColorName(),
            'vehicle_type'        => fake()->randomElement(['sedan', 'suv', 'truck', 'van', 'bakkie']),
            'fuel_type'           => fake()->randomElement(['petrol', 'diesel']),
            'transmission'        => fake()->randomElement(['automatic', 'manual']),
            'engine_capacity'     => fake()->randomElement([1.4, 1.6, 2.0, 2.2, 3.0]),
            'seating_capacity'    => fake()->randomElement([5, 7, 9]),
            'odometer_reading'    => fake()->numberBetween(0, 200000),
            'status'              => 'available',
            'location'            => fake()->city(),
            'purchase_date'       => fake()->dateTimeBetween('-4 years', '-6 months')->format('Y-m-d'),
            'purchase_price'      => fake()->randomFloat(2, 150000, 800000),
            'current_value'       => fake()->randomFloat(2, 80000, 600000),
            'approval_status'     => 'approved',
            'version'             => 1,
            'created_by'          => null,
            'updated_by'          => null,
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => 'available']);
    }

    public function booked(): static
    {
        return $this->state(['status' => 'booked']);
    }

    public function inMaintenance(): static
    {
        return $this->state(['status' => 'maintenance']);
    }

    public function disposed(): static
    {
        return $this->state([
            'status'         => 'disposed',
            'disposal_date'  => now()->toDateString(),
            'disposal_reason'=> 'End of life',
            'disposal_type'  => 'sold',
        ]);
    }

    public function diesel(): static
    {
        return $this->state(['fuel_type' => 'diesel']);
    }

    public function petrol(): static
    {
        return $this->state(['fuel_type' => 'petrol']);
    }
}
