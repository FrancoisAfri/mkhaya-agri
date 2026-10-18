<?php

namespace Database\Factories\Fleet;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FleetBookingFactory extends Factory
{
    protected $model = FleetBooking::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+7 days');
        $end   = fake()->dateTimeBetween('+8 days', '+14 days');

        return [
            'uuid'               => (string) Str::uuid(),
            'reference'          => 'BK-' . strtoupper(\Illuminate\Support\Str::random(8)),
            'vehicle_id'         => FleetVehicle::factory(),
            'driver_id'          => FleetDriver::factory(),
            'requester_id'       => User::factory(),
            'purpose'            => fake()->sentence(6),
            'destination'        => fake()->city(),
            'departure_at'       => $start->format('Y-m-d H:i:s'),
            'expected_return_at' => $end->format('Y-m-d H:i:s'),
            'usage_type'         => fake()->randomElement(['official', 'local', 'personal', 'business']),
            'is_local_trip'      => fake()->boolean(30),
            'passenger_count'    => fake()->numberBetween(0, 4),
            'workflow_state'     => 'pending',
            'version'            => 1,
            'created_by'         => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['workflow_state' => 'pending']);
    }

    public function approved(): static
    {
        return $this->state(['workflow_state' => 'approved']);
    }

    public function collected(): static
    {
        return $this->state([
            'workflow_state' => 'collected',
            'collected_at'   => now(),
            'collected_by'   => null,
        ]);
    }

    public function inUse(): static
    {
        return $this->state([
            'workflow_state'    => 'security_out',
            'start_odometer'    => fake()->numberBetween(10000, 80000),
            'collected_at'      => now(),
            'collected_by'      => null,
        ]);
    }

    public function returned(): static
    {
        $startOdometer = fake()->numberBetween(10000, 80000);
        $kmTravelled   = fake()->numberBetween(50, 800);

        return $this->state([
            'workflow_state' => 'returned',
            'start_odometer' => $startOdometer,
            'end_odometer'   => $startOdometer + $kmTravelled,
            'km_travelled'   => $kmTravelled,
            'collected_at'   => now()->subDays(3),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'workflow_state'       => 'cancelled',
            'cancellation_reason'  => 'No longer required',
        ]);
    }
}
