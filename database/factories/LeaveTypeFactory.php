<?php

namespace Database\Factories;

use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return [
            'name'                          => $this->faker->words(2, true) . ' Leave',
            'status'                        => 1,
            'statutory_type'                => $this->faker->randomElement(['annual', 'sick', 'family_responsibility', null]),
            'is_paid'                       => true,
            'bcea_minimum_days'             => 15,
            'require_probation_period_days' => 182,
            'max_consecutive_hours'         => null,
            'doc_required'                  => 0,
            'notes_required'                => 0,
            'requires_medical_certificate'  => false,
        ];
    }
}
