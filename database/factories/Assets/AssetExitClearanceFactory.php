<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetExitClearance;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetExitClearanceFactory extends Factory
{
    protected $model = AssetExitClearance::class;

    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'initiated_by'     => User::factory(),
            'target_exit_date' => now()->addDays(14)->toDateString(),
            'status'           => 'pending',
            'notes'            => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    public function cleared(): static
    {
        return $this->state([
            'status'      => 'cleared',
            'cleared_at'  => now(),
        ]);
    }
}
