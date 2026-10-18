<?php

namespace Tests\Unit\HR\Leave;

use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkingDaysCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkingDaysCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed a known recurring public holiday: 1 Jan (New Year's Day)
        DB::table('public_holidays')->insert([
            ['day' => '01-01', 'year' => 0, 'name' => "New Year's Day"],
            ['day' => '04-27', 'year' => 0, 'name' => 'Freedom Day'],
            ['day' => '06-16', 'year' => 0, 'name' => 'Youth Day'],
            ['day' => '08-09', 'year' => 0, 'name' => "National Women's Day"],
            ['day' => '09-24', 'year' => 0, 'name' => 'Heritage Day'],
            ['day' => '12-16', 'year' => 0, 'name' => 'Day of Reconciliation'],
            ['day' => '12-25', 'year' => 0, 'name' => 'Christmas Day'],
            ['day' => '12-26', 'year' => 0, 'name' => 'Day of Goodwill'],
        ]);

        $this->calculator = new WorkingDaysCalculator();
    }

    // ── Basic counting ────────────────────────────────────────────────────────

    public function test_single_working_day(): void
    {
        // Monday 5 May 2025 — no holiday
        $days = $this->calculator->calculate('2025-05-05', '2025-05-05');
        $this->assertEquals(1.0, $days);
    }

    public function test_full_week_is_five_working_days(): void
    {
        $days = $this->calculator->calculate('2025-05-05', '2025-05-09');
        $this->assertEquals(5.0, $days);
    }

    public function test_weekend_days_are_excluded(): void
    {
        // Mon–Sun: Mon–Fri are working, Sat/Sun are not
        $days = $this->calculator->calculate('2025-05-05', '2025-05-11');
        $this->assertEquals(5.0, $days);
    }

    public function test_saturday_only_returns_zero(): void
    {
        $days = $this->calculator->calculate('2025-05-10', '2025-05-10');
        $this->assertEquals(0.0, $days);
    }

    public function test_sunday_only_returns_zero(): void
    {
        $days = $this->calculator->calculate('2025-05-11', '2025-05-11');
        $this->assertEquals(0.0, $days);
    }

    // ── Public holiday exclusion ──────────────────────────────────────────────

    public function test_public_holiday_is_excluded(): void
    {
        // 16 June 2025 (Monday) is Youth Day — a 5-day week Mon–Fri should have 4 days
        $days = $this->calculator->calculate('2025-06-16', '2025-06-20');
        $this->assertEquals(4.0, $days);
    }

    public function test_christmas_and_day_of_goodwill_excluded(): void
    {
        // 25–26 Dec 2025 are Thu/Fri; week Mon–Fri: Mon+Tue+Wed = 3 working days
        $days = $this->calculator->calculate('2025-12-22', '2025-12-26');
        $this->assertEquals(3.0, $days);
    }

    // ── Sunday-shift substitute holiday ──────────────────────────────────────

    public function test_monday_substitute_when_holiday_falls_on_sunday(): void
    {
        // 27 Apr 2025 (Freedom Day) falls on a Sunday → Monday 28 Apr 2025 is the substitute
        // Week Mon 28 Apr – Fri 2 May: only 4 days (Mon is substitute holiday)
        $days = $this->calculator->calculate('2025-04-28', '2025-05-02');
        $this->assertEquals(4.0, $days);
    }

    public function test_sunday_holiday_itself_is_not_counted(): void
    {
        // Sunday 27 Apr is already not a working day — still zero
        $days = $this->calculator->calculate('2025-04-27', '2025-04-27');
        $this->assertEquals(0.0, $days);
    }

    // ── Six-day week ─────────────────────────────────────────────────────────

    public function test_six_day_week_counts_saturday(): void
    {
        // Mon–Sat: 6 working days
        $days = $this->calculator->calculate('2025-05-05', '2025-05-10', sixDayWeek: true);
        $this->assertEquals(6.0, $days);
    }

    public function test_six_day_week_still_excludes_sunday(): void
    {
        $days = $this->calculator->calculate('2025-05-11', '2025-05-11', sixDayWeek: true);
        $this->assertEquals(0.0, $days);
    }

    // ── Hourly calculation ────────────────────────────────────────────────────

    public function test_hourly_same_day_eight_hours(): void
    {
        $hours = $this->calculator->calculateHours('2025-05-05', '2025-05-05', '08:00', '16:00');
        $this->assertEquals(8.0, $hours);
    }

    public function test_hourly_partial_day(): void
    {
        $hours = $this->calculator->calculateHours('2025-05-05', '2025-05-05', '09:00', '13:30');
        $this->assertEquals(4.5, $hours);
    }

    public function test_hourly_returns_zero_when_end_before_start(): void
    {
        $hours = $this->calculator->calculateHours('2025-05-05', '2025-05-05', '16:00', '08:00');
        $this->assertEquals(0.0, $hours);
    }

    // ── Easter dates (no DB seed needed — calculated) ─────────────────────────

    public function test_good_friday_2025_is_excluded(): void
    {
        // Good Friday 2025 = 18 Apr; Mon 14 Apr – Fri 18 Apr → 4 working days
        $days = $this->calculator->calculate('2025-04-14', '2025-04-18');
        $this->assertEquals(4.0, $days);
    }

    public function test_family_day_2025_is_excluded(): void
    {
        // Family Day 2025 = 21 Apr (Monday after Easter); that week has 4 working days
        $days = $this->calculator->calculate('2025-04-21', '2025-04-25');
        $this->assertEquals(4.0, $days);
    }

    // ── isWorkingDay helper ───────────────────────────────────────────────────

    public function test_is_working_day_returns_true_for_normal_weekday(): void
    {
        $this->assertTrue($this->calculator->isWorkingDay(Carbon::parse('2025-05-07')));
    }

    public function test_is_working_day_returns_false_for_saturday(): void
    {
        $this->assertFalse($this->calculator->isWorkingDay(Carbon::parse('2025-05-10')));
    }

    public function test_is_working_day_returns_false_for_public_holiday(): void
    {
        $this->assertFalse($this->calculator->isWorkingDay(Carbon::parse('2025-06-16')));
    }

    // ── addWorkingDays ────────────────────────────────────────────────────────

    public function test_add_working_days_skips_weekend(): void
    {
        // Friday + 1 working day = Monday
        $result = $this->calculator->addWorkingDays(Carbon::parse('2025-05-09'), 1);
        $this->assertEquals('2025-05-12', $result->format('Y-m-d'));
    }

    public function test_add_working_days_skips_public_holiday(): void
    {
        // 13 Jun 2025 (Friday) + 1 working day; 16 Jun is Youth Day (Mon) → lands on Tue 17 Jun
        $result = $this->calculator->addWorkingDays(Carbon::parse('2025-06-13'), 1);
        $this->assertEquals('2025-06-17', $result->format('Y-m-d'));
    }
}
