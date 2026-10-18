<?php

namespace Database\Factories\Recruitment;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'candidate_id'    => Candidate::factory(),
            'job_id'          => Job::factory(),
            'pipeline_stage_id' => null,
            'recruiter_id'    => User::factory(),
            'status'          => ApplicationStatus::Applied->value,
            'source'          => fake()->randomElement(['direct', 'linkedin', 'referral']),
            'cover_letter'    => fake()->paragraph(),
            'applied_at'      => now(),
        ];
    }

    public function inStatus(ApplicationStatus $status): static
    {
        return $this->state(['status' => $status->value]);
    }

    public function rejected(string $reason = 'Not the right fit.'): static
    {
        return $this->state([
            'status'          => ApplicationStatus::Rejected->value,
            'rejected_at'     => now(),
            'rejected_reason' => $reason,
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state([
            'status'       => ApplicationStatus::Withdrawn->value,
            'withdrawn_at' => now(),
        ]);
    }
}
