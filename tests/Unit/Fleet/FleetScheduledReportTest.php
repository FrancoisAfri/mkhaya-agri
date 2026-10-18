<?php

namespace Tests\Unit\Fleet;

use App\Modules\Fleet\Models\FleetScheduledReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetScheduledReportTest extends TestCase
{
    use RefreshDatabase;

    // ── computeNextRun — daily ────────────────────────────────────────────────

    public function test_daily_cadence_returns_next_day_at_run_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 06:00:00'));

        $report = new FleetScheduledReport([
            'cadence'    => 'daily',
            'run_time'   => '07:00',
            'active'     => true,
        ]);

        $next = $report->computeNextRun();

        $this->assertEquals('2026-05-17 07:00:00', $next->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_daily_cadence_after_run_time_schedules_for_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00'));

        $report = new FleetScheduledReport([
            'cadence'  => 'daily',
            'run_time' => '07:00',
            'active'   => true,
        ]);

        $next = $report->computeNextRun();

        $this->assertEquals('2026-05-17 07:00:00', $next->toDateTimeString());

        Carbon::setTestNow();
    }

    // ── computeNextRun — weekly ───────────────────────────────────────────────

    public function test_weekly_cadence_targets_correct_iso_weekday(): void
    {
        // 2026-05-18 is a Monday (ISO weekday 1)
        Carbon::setTestNow(Carbon::parse('2026-05-16 06:00:00')); // Saturday

        $report = new FleetScheduledReport([
            'cadence'     => 'weekly',
            'run_time'    => '08:00',
            'day_of_week' => 1, // Monday
            'active'      => true,
        ]);

        $next = $report->computeNextRun();

        $this->assertEquals(1, $next->isoWeekday());
        $this->assertEquals('08:00', $next->format('H:i'));
        $this->assertTrue($next->gt(Carbon::now()));

        Carbon::setTestNow();
    }

    public function test_weekly_cadence_skips_past_time_on_same_weekday(): void
    {
        // Monday at 10:00 — run_time is 08:00 so must push to NEXT Monday
        Carbon::setTestNow(Carbon::parse('2026-05-18 10:00:00'));

        $report = new FleetScheduledReport([
            'cadence'     => 'weekly',
            'run_time'    => '08:00',
            'day_of_week' => 1,
            'active'      => true,
        ]);

        $next = $report->computeNextRun();

        // Should be next Monday, not today
        $this->assertTrue($next->gt(Carbon::now()));
        $this->assertEquals(1, $next->isoWeekday());

        Carbon::setTestNow();
    }

    // ── computeNextRun — monthly ──────────────────────────────────────────────

    public function test_monthly_cadence_targets_correct_day_of_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 06:00:00'));

        $report = new FleetScheduledReport([
            'cadence'       => 'monthly',
            'run_time'      => '07:00',
            'day_of_month'  => 15,
            'active'        => true,
        ]);

        $next = $report->computeNextRun();

        $this->assertEquals(15, $next->day);
        $this->assertEquals('07:00', $next->format('H:i'));

        Carbon::setTestNow();
    }

    public function test_monthly_cadence_pushes_to_next_month_if_day_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));

        $report = new FleetScheduledReport([
            'cadence'      => 'monthly',
            'run_time'     => '07:00',
            'day_of_month' => 15,
            'active'       => true,
        ]);

        $next = $report->computeNextRun();

        $this->assertEquals(6, $next->month); // June
        $this->assertEquals(15, $next->day);

        Carbon::setTestNow();
    }

    public function test_monthly_cadence_clamps_day_to_28_for_safety(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 06:00:00'));

        $report = new FleetScheduledReport([
            'cadence'      => 'monthly',
            'run_time'     => '07:00',
            'day_of_month' => 31, // will be clamped to 28
            'active'       => true,
        ]);

        $next = $report->computeNextRun();

        $this->assertLessThanOrEqual(28, $next->day);

        Carbon::setTestNow();
    }
}
