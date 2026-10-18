<?php

namespace Database\Factories\Recruitment;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\Shortlist;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShortlistFactory extends Factory
{
    protected $model = Shortlist::class;

    public function definition(): array
    {
        return [
            'job_id'       => Job::factory(),
            'generated_by' => User::factory(),
            'status'       => ShortlistStatus::Draft->value,
            'notes'        => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => ShortlistStatus::Pending->value]);
    }

    public function approved(int $approverId): static
    {
        return $this->state([
            'status'      => ShortlistStatus::Approved->value,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }
}
