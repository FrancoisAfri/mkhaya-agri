<?php

namespace Database\Factories\Recruitment;

use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;

class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        return [
            'first_name'    => fake()->firstName(),
            'last_name'     => fake()->lastName(),
            'email'         => fake()->unique()->safeEmail(),
            'phone'         => fake()->phoneNumber(),
            'nationality'   => 'South African',
            'gender'        => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-50 years', '-20 years')->format('Y-m-d'),
            'linkedin_url'  => null,
            'is_duplicate_of' => null,
            'purge_at'      => null,
        ];
    }

    public function withAiConsent(): static
    {
        return $this->state([
            'ai_consent_at' => now(),
            'ai_consent_ip' => '127.0.0.1',
        ]);
    }

    public function duplicate(Candidate $canonical): static
    {
        return $this->state(['is_duplicate_of' => $canonical->id]);
    }
}
