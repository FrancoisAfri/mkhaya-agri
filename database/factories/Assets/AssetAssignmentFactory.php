<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetAssignmentFactory extends Factory
{
    protected $model = AssetAssignment::class;

    public function definition(): array
    {
        return [
            'asset_id'        => Assets::factory(),
            'assigned_to'     => User::factory(),
            'assigned_by'     => User::factory(),
            'location_id'     => null,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
            'return_due_date' => null,
            'returned_at'     => null,
            'is_current'      => true,
            'approval_id'     => null,
            'notes'           => null,
        ];
    }

    public function returned(): static
    {
        return $this->state([
            'is_current'  => false,
            'returned_at' => now(),
        ]);
    }

    public function temporary(): static
    {
        return $this->state([
            'assignment_type' => 'temporary',
            'return_due_date' => now()->addDays(30)->toDateString(),
        ]);
    }
}
