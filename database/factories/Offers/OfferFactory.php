<?php

namespace Database\Factories\Offers;

use App\Core\User\Models\User;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'application_id'  => Application::factory(),
            'job_id'          => Job::factory(),
            'candidate_id'    => Candidate::factory(),
            'created_by'      => User::factory(),
            'position_title'  => fake()->jobTitle(),
            'department'      => fake()->word(),
            'start_date'      => now()->addMonths(2)->toDateString(),
            'salary_amount'   => fake()->numberBetween(20000, 80000),
            'salary_currency' => 'ZAR',
            'contract_type'   => 'permanent',
            'notes'           => null,
            'status'          => OfferStatus::Draft->value,
        ];
    }

    public function sent(): static
    {
        return $this->state([
            'status'           => OfferStatus::Sent->value,
            'token_hash'       => hash('sha256', Str::random(64)),
            'token_expires_at' => now()->addHours(48),
            'sent_at'          => now(),
            'expires_at'       => now()->addHours(48),
        ]);
    }

    public function accepted(): static
    {
        return $this->state([
            'status'      => OfferStatus::Accepted->value,
            'accepted_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state([
            'status'          => OfferStatus::Declined->value,
            'declined_at'     => now(),
            'decline_reason'  => 'Found a better opportunity.',
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status'     => OfferStatus::Expired->value,
            'expires_at' => now()->subHour(),
        ]);
    }

    public function withOtp(string $rawOtp = '123456'): static
    {
        return $this->state([
            'otp_hash'       => hash('sha256', $rawOtp),
            'otp_expires_at' => now()->addMinutes(15),
            'otp_attempts'   => 0,
        ]);
    }
}
