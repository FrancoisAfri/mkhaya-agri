<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetApprovalStepFactory extends Factory
{
    protected $model = AssetApprovalStep::class;

    public function definition(): array
    {
        return [
            'approval_id' => AssetApproval::factory(),
            'step_number' => 1,
            'approver_id' => User::factory(),
            'status'      => ApprovalStatus::Pending->value,
            'decided_at'  => null,
            'notes'       => null,
        ];
    }

    public function approved(): static
    {
        return $this->state([
            'status'     => ApprovalStatus::Approved->value,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status'     => ApprovalStatus::Rejected->value,
            'decided_at' => now(),
        ]);
    }
}
