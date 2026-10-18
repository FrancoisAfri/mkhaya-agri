<?php

namespace Database\Factories\Fleet;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetScheduledReport;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetScheduledReportFactory extends Factory
{
    protected $model = FleetScheduledReport::class;

    public function definition(): array
    {
        $reportKeys = [
            'vehicle_details', 'vehicle_expenditure', 'fuel_consumption',
            'driver_scorecard', 'document_expiry', 'incident_report',
            'carbon_emissions', 'booking_utilisation',
        ];

        return [
            'name'         => fake()->words(4, true) . ' Report',
            'report_key'   => fake()->randomElement($reportKeys),
            'cadence'      => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'run_time'     => fake()->randomElement(['06:00', '07:00', '08:00', '09:00']),
            'day_of_week'  => null,
            'day_of_month' => null,
            'format'       => fake()->randomElement(['pdf', 'excel', 'both']),
            'recipients'   => fake()->email() . ',' . fake()->email(),
            'active'       => true,
            'next_run_at'  => null,
            'created_by'   => null,
        ];
    }

    public function weekly(): static
    {
        return $this->state([
            'cadence'     => 'weekly',
            'day_of_week' => 1,
        ]);
    }

    public function monthly(): static
    {
        return $this->state([
            'cadence'      => 'monthly',
            'day_of_month' => 1,
        ]);
    }

    public function daily(): static
    {
        return $this->state(['cadence' => 'daily']);
    }

    public function paused(): static
    {
        return $this->state(['active' => false]);
    }
}
