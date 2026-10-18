<?php

namespace Database\Factories\Recruitment;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Enums\VacancyType;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Database\Eloquent\Factories\Factory;

class RequisitionFactory extends Factory
{
    protected $model = Requisition::class;

    public function definition(): array
    {
        return [
            'requisition_number' => Requisition::generateRequisitionNumber(),
            'title'              => fake()->jobTitle(),
            'department'         => fake()->randomElement(['Engineering', 'Sales', 'Finance', 'Operations']),
            'job_type'           => VacancyType::Permanent->value,
            'requested_by'       => User::factory(),
            'status'             => RequisitionStatus::Draft->value,
            'headcount'          => 1,
            'reason'             => fake()->sentence(),
            'target_start_date'  => now()->addMonths(2)->toDateString(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => RequisitionStatus::PendingApproval->value]);
    }

    public function approved(int $approverId): static
    {
        return $this->state([
            'status'      => RequisitionStatus::Approved->value,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }
}
