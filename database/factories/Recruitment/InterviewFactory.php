<?php

namespace Database\Factories\Recruitment;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Enums\InterviewType;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Interview;
use Illuminate\Database\Eloquent\Factories\Factory;

class InterviewFactory extends Factory
{
    protected $model = Interview::class;

    public function definition(): array
    {
        return [
            'application_id'   => Application::factory(),
            'interviewer_id'   => User::factory(),
            'type'             => InterviewType::Technical->value,
            'scheduled_at'     => now()->addDays(3),
            'duration_minutes' => 60,
            'location'         => fake()->address(),
            'meeting_url'      => null,
            'status'           => 'scheduled',
            'notes'            => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }
}
