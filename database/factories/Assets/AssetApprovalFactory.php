<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetApprovalFactory extends Factory
{
    protected $model = AssetApproval::class;

    public function definition(): array
    {
        return [
            'type'             => ApprovalType::Assignment->value,
            'approvable_type'  => Assets::class,
            'approvable_id'    => Assets::factory(),
            'requested_by'     => User::factory(),
            'current_step'     => 1,
            'total_steps'      => 1,
            'status'           => ApprovalStatus::Pending->value,
            'notes'            => null,
            'completed_at'     => null,
        ];
    }

    public function transfer(): static
    {
        return $this->state(['type' => ApprovalType::Transfer->value]);
    }

    public function disposal(): static
    {
        return $this->state(['type' => ApprovalType::Disposal->value]);
    }

    public function approved(): static
    {
        return $this->state([
            'status'       => ApprovalStatus::Approved->value,
            'completed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status'       => ApprovalStatus::Rejected->value,
            'completed_at' => now(),
        ]);
    }

    public function multiStep(int $steps): static
    {
        return $this->state([
            'total_steps'  => $steps,
            'current_step' => 1,
        ]);
    }
}
