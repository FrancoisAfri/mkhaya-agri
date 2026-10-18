<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Enums\MaintenanceStatus;
use App\Modules\Assets\Enums\MaintenanceType;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetMaintenanceScheduleFactory extends Factory
{
    protected $model = AssetMaintenanceSchedule::class;

    public function definition(): array
    {
        return [
            'asset_id'         => Assets::factory(),
            'title'            => fake()->sentence(4),
            'description'      => fake()->sentence(),
            'maintenance_type' => fake()->randomElement(MaintenanceType::cases())->value,
            'status'           => MaintenanceStatus::Scheduled,
            'assigned_to'      => null,
            'vendor_name'      => null,
            'estimated_cost'   => fake()->randomFloat(2, 100, 5000),
            'actual_cost'      => null,
            'due_date'         => now()->addDays(30)->toDateString(),
            'started_at'       => null,
            'completed_at'     => null,
            'next_due_date'    => null,
            'recurrence_days'  => null,
            'approval_id'      => null,
            'notes'            => null,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status'       => MaintenanceStatus::Completed,
            'started_at'   => now()->subDays(5),
            'completed_at' => now(),
            'actual_cost'  => fake()->randomFloat(2, 100, 5000),
        ]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status'   => MaintenanceStatus::Scheduled,
            'due_date' => now()->subDays(7)->toDateString(),
        ]);
    }
}
