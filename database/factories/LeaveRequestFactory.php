<?php

namespace Database\Factories;

use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-6 months', 'now');
        $end   = (clone $start)->modify('+' . $this->faker->numberBetween(1, 5) . ' days');

        return [
            'uuid'           => Str::uuid(),
            'request_number' => 'LVR-' . strtoupper(Str::random(6)),
            'employee_id'    => 1,
            'leave_type_id'  => LeaveType::factory(),
            'request_mode'   => 'daily',
            'is_overtime'    => false,
            'is_bulk'        => false,
            'start_date'     => $start->format('Y-m-d'),
            'end_date'       => $end->format('Y-m-d'),
            'working_days'   => 1,
            'working_hours'  => 8,
            'hours_reserved' => 8,
            'status'         => 'approved',
            'submitted_at'   => now(),
        ];
    }
}
