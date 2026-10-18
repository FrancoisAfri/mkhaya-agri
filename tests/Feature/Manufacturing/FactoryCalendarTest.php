<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\Shutdown;
use App\Modules\Operations\Manufacturing\Services\FactoryCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MFG-001.04 -- shifts, working calendars, public holidays and planned shutdowns, combined.
 *
 * Every figure is a day shift of 06:00-14:00 with a 30-minute break unless the test says
 * otherwise: 480 minutes on the floor, 450 paid. Dates are worked out from a fixed Monday so
 * none of them can land on Easter or a real public holiday by accident.
 */
class FactoryCalendarTest extends ManufacturingTestCase
{
    private Factory $plant;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday in November: no South African public holiday falls in this week.
        $this->monday = Carbon::create(2026, 11, 2);
        $this->assertTrue($this->monday->isMonday());

        $this->plant = $this->factory('CAL');
        $this->shift('DAY', '06:00', '14:00', 30, [1, 2, 3, 4, 5]);
    }

    private function shift(string $code, string $from, string $to, int $break, array $days, array $extra = []): Shift
    {
        return $this->asCompany($this->company, fn () => Shift::create(array_merge([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => $code, 'name' => $code,
            'starts_at' => $from, 'ends_at' => $to, 'break_minutes' => $break, 'days_of_week' => $days,
            'overtime_multiplier' => 1.5, 'observes_public_holidays' => true, 'is_active' => true,
        ], $extra)));
    }

    private function shutdown(string $from, string $to, ?int $lineId = null): void
    {
        $this->asCompany($this->company, fn () => Shutdown::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'production_line_id' => $lineId, 'reason' => 'Test', 'kind' => 'PLANNED',
            'starts_at' => Carbon::parse($from, 'Africa/Johannesburg'),
            'ends_at' => Carbon::parse($to, 'Africa/Johannesburg'),
        ]));
    }

    private function day(Carbon $date, ?int $lineId = null): array
    {
        return $this->asCompany($this->company, fn () => (new FactoryCalendar)->day($this->plant, $date, $lineId));
    }

    public function test_a_working_day_has_its_shift_less_the_break(): void
    {
        $this->assertSame(450, $this->day($this->monday)['minutes']);
    }

    public function test_a_day_the_shift_does_not_run_has_nothing(): void
    {
        $this->assertSame(0, $this->day($this->monday->copy()->next(Carbon::SATURDAY))['minutes']);
    }

    public function test_a_week_adds_up(): void
    {
        // Monday to Sunday: five working days of 450.
        $total = $this->asCompany($this->company, fn () => (new FactoryCalendar)
            ->minutesBetween($this->plant, $this->monday, $this->monday->copy()->addDays(6)));

        $this->assertSame(5 * 450, $total);
    }

    public function test_a_public_holiday_stops_a_shift_that_observes_it_and_not_one_that_does_not(): void
    {
        $wednesday = $this->monday->copy()->addDays(2);

        DB::table('public_holidays')->insert([
            'name' => 'Test holiday', 'day' => $wednesday->format('m-d'), 'year' => $wednesday->year,
            'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A maintenance crew that works holidays: 14:00-18:00, no break = 240.
        $this->shift('MAINT', '14:00', '18:00', 0, [3], ['observes_public_holidays' => false]);

        $day = $this->day($wednesday);

        $this->assertTrue($day['is_holiday']);
        $this->assertSame(240, $day['minutes']);
        $this->assertSame('HOLIDAY', collect($day['shifts'])->firstWhere('code', 'DAY')['reason']);
    }

    public function test_a_shutdown_covering_the_shift_removes_all_of_it_break_included(): void
    {
        $this->shutdown($this->monday->toDateString().' 00:00', $this->monday->toDateString().' 23:59');

        $day = $this->day($this->monday);

        $this->assertSame(0, $day['minutes']);
        $this->assertSame('SHUTDOWN', $day['shifts'][0]['reason']);
    }

    public function test_a_partial_shutdown_removes_only_the_overlap(): void
    {
        // 10:00-12:00 lost from a 06:00-14:00 shift: 480 - 120 - 30 break = 330.
        $this->shutdown($this->monday->toDateString().' 10:00', $this->monday->toDateString().' 12:00');

        $this->assertSame(330, $this->day($this->monday)['minutes']);
    }

    public function test_overlapping_shutdowns_do_not_remove_the_same_hour_twice(): void
    {
        // 09:00-11:00 and 10:00-12:00 together cover 09:00-12:00: 180 lost, not 240.
        $this->shutdown($this->monday->toDateString().' 09:00', $this->monday->toDateString().' 11:00');
        $this->shutdown($this->monday->toDateString().' 10:00', $this->monday->toDateString().' 12:00');

        $this->assertSame(480 - 180 - 30, $this->day($this->monday)['minutes']);
    }

    public function test_a_stopped_line_does_not_stop_the_factory_but_does_stop_itself(): void
    {
        $line = $this->asCompany($this->company, fn () => $this->plant->productionLines()->create([
            'company_id' => $this->company->id, 'code' => 'L1', 'name' => 'Line 1', 'is_active' => true,
        ]));

        $this->shutdown($this->monday->toDateString().' 00:00', $this->monday->toDateString().' 23:59', $line->id);

        $this->assertSame(450, $this->day($this->monday)['minutes'], 'A line shutdown stopped the whole factory.');
        $this->assertSame(0, $this->day($this->monday, $line->id)['minutes']);
    }

    public function test_a_night_shift_is_capacity_of_the_day_it_starts(): void
    {
        // Friday 22:00 to Saturday 06:00, 30 min break: 450, all of it Friday's.
        $this->shift('NIGHT', '22:00', '06:00', 30, [5], ['crosses_midnight' => true]);

        $friday = $this->monday->copy()->addDays(4);

        $this->assertSame(450 + 450, $this->day($friday)['minutes']);   // day shift + night shift
        $this->assertSame(0, $this->day($friday->copy()->addDay())['minutes']);
    }

    public function test_a_shutdown_on_the_morning_after_cuts_into_last_nights_shift(): void
    {
        $this->shift('NIGHT', '22:00', '06:00', 30, [1], ['crosses_midnight' => true]);

        // Tuesday 04:00-06:00 falls inside Monday's night shift.
        $tuesday = $this->monday->copy()->addDay()->toDateString();
        $this->shutdown("{$tuesday} 04:00", "{$tuesday} 06:00");

        $night = collect($this->day($this->monday)['shifts'])->firstWhere('code', 'NIGHT');

        $this->assertSame(480 - 120 - 30, $night['minutes']);
        $this->assertSame('PARTIAL_SHUTDOWN', $night['reason']);
    }

    public function test_an_inactive_shift_contributes_nothing(): void
    {
        DB::table('mfg_shifts')->where('code', 'DAY')->update(['is_active' => false]);

        $this->assertSame(0, $this->day($this->monday)['minutes']);
    }

    public function test_a_shutdown_typed_on_the_screen_is_read_in_the_factorys_own_timezone(): void
    {
        // The browser sends wall-clock time with no zone. 06:00 at a Johannesburg plant is 04:00 UTC.
        $this->actingAs($this->configurator)->post(route('manufacturing.setup.shutdowns.store'), [
            'factory_uuid' => $this->plant->uuid, 'reason' => 'Typed', 'kind' => 'PLANNED',
            'starts_at' => $this->monday->toDateString().'T10:00', 'ends_at' => $this->monday->toDateString().'T12:00',
        ])->assertSessionHasNoErrors();

        $raw = DB::table('mfg_shutdowns')->where('reason', 'Typed')->value('starts_at');
        $this->assertSame(
            Carbon::parse($this->monday->toDateString().' 10:00', 'Africa/Johannesburg')->getTimestamp(),
            Carbon::parse($raw)->getTimestamp(),
            'A shutdown typed as 10:00 local time was stored at a different instant.'
        );

        // And the calendar cuts exactly 10:00-12:00 local out of the 06:00-14:00 shift.
        $this->assertSame(480 - 120 - 30, $this->day($this->monday)['minutes']);
    }
}
