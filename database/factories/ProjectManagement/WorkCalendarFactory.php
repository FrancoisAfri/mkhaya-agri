<?php

namespace Database\Factories\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkCalendar>
 */
class WorkCalendarFactory extends Factory
{
    protected $model = WorkCalendar::class;

    public function definition(): array
    {
        return [
            'name'      => 'Standard calendar',
            'timezone'  => 'Africa/Johannesburg',
            'work_week' => WorkCalendar::STANDARD_WEEK,
        ];
    }
}
