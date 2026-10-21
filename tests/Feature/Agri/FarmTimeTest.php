<?php

namespace Tests\Feature\Agri;

use App\Modules\HR\Hr\Attendance\Models\RosteredShift as FarmShift;
use App\Modules\Agri\Models\FarmWorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * The application runs in UTC; a farm lives on its own clock. Stored as UTC, shown and typed in
 * farm time — the test farms are in Africa/Johannesburg (UTC+2).
 */
class FarmTimeTest extends AgriTestCase
{
    public function test_a_time_typed_on_a_farm_screen_is_the_farms_clock_and_is_stored_as_utc(): void
    {
        $this->catalogue();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $day = today()->addDays(3)->toDateString();
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), ['farm_id' => $farm->uuid, 'category' => 'spraying', 'title' => 'Spray',
            'priority' => 'normal', 'planned_start' => "{$day}T06:00", 'planned_end' => "{$day}T10:30"])->assertSessionHasNoErrors();

        $wo = FarmWorkOrder::withoutGlobalScopes()->sole();
        $this->assertSame("{$day} 04:00:00", DB::table('farm_work_orders')->where('id', $wo->id)->value('planned_start'), 'Stored as UTC.');
        $this->assertSame('06:00', $wo->planned_start->format('H:i'), 'Read back on the farm\'s clock.');
        $this->assertSame('Africa/Johannesburg', $wo->planned_start->getTimezone()->getName());

        // The job page shows it as typed.
        $this->actingAs($this->manager)->get(route('agri.work-orders.show', $wo->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->where('order.planned_start', "{$day} 06:00"));
    }

    public function test_a_shift_template_s_hours_are_the_farm_s_hours_and_queries_compare_moments(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->template('DAY', '06:00', '14:00');
        $date = today()->addDays(2)->toDateString();
        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.store'), ['farm_id' => $farm->uuid,
            'template_id' => \App\Modules\HR\Hr\Attendance\Models\ShiftTemplate::withoutGlobalScopes()->value('id'), 'date' => $date])->assertSessionHasNoErrors();

        $shift = FarmShift::withoutGlobalScopes()->sole();
        $this->assertSame("{$date} 04:00:00", DB::table('attendance_rostered_shifts')->value('starts_at'));
        $this->assertSame('06:00', $shift->starts_at->format('H:i'));
        // A farm-time moment used in a query finds the same row: it is bound as UTC.
        $this->assertTrue(FarmShift::withoutGlobalScopes()->where('starts_at', $shift->starts_at)->exists());
        $this->assertTrue(FarmShift::withoutGlobalScopes()->where('starts_at', '<', $shift->starts_at->copy()->addMinute())->exists());
    }
}
