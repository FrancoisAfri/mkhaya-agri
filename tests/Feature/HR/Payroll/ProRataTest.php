<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Services\ProRataCalculator;
use Tests\TestCase;

/**
 * Paying for the part of the period somebody was actually employed.
 *
 * The run scaled a monthly package to the pay period and paid it, without ever reading a start or
 * a leaving date — so a joiner on the 20th was paid the whole month and a leaver on the 3rd was
 * paid to the 31st. That is money out of the door rather than a missing feature.
 */
class ProRataTest extends TestCase
{
    private function calc(): ProRataCalculator
    {
        return app(ProRataCalculator::class);
    }

    // ── A full period is exactly a full period ───────────────────────────────

    public function test_somebody_employed_all_month_is_paid_in_full(): void
    {
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2020-01-01', null);

        // Exactly 1.0, not a division that happens to come out near it — most payslips take this
        // path and none of them should drift by a cent.
        $this->assertSame(1.0, $result['fraction']);
        $this->assertFalse($result['partial']);
        $this->assertSame(15000.0, $this->calc()->apply(15000, $result));
    }

    public function test_somebody_who_joined_before_the_period_is_not_treated_as_partial(): void
    {
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-08-15');

        $this->assertFalse($result['partial']);
        $this->assertSame(1.0, $result['fraction']);
    }

    // ── Joiners and leavers ──────────────────────────────────────────────────

    public function test_a_mid_month_joiner_is_paid_for_the_days_they_worked(): void
    {
        // September 2026: 22 working days. Starting the 21st leaves 8 of them.
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-09-21');

        $this->assertTrue($result['partial']);
        $this->assertSame(8, $result['days_paid']);
        $this->assertSame(22, $result['days_in_period']);
        $this->assertEqualsWithDelta(15000 * 8 / 22, $this->calc()->apply(15000, $result), 0.01);
    }

    public function test_a_mid_month_leaver_is_paid_to_their_last_day(): void
    {
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2020-01-01', '2026-09-03');

        $this->assertTrue($result['partial']);
        $this->assertSame(3, $result['days_paid']);
        $this->assertStringContainsString('left 2026-09-03', $result['reason']);
    }

    public function test_somebody_who_joins_and_leaves_inside_one_period(): void
    {
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-09-07', '2026-09-11');

        // One working week.
        $this->assertSame(5, $result['days_paid']);
    }

    // ── Nothing owed ─────────────────────────────────────────────────────────

    public function test_somebody_who_left_before_the_period_is_owed_nothing(): void
    {
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2020-01-01', '2026-08-15');

        $this->assertSame(0.0, $result['fraction']);
        $this->assertSame(0.0, $this->calc()->apply(15000, $result));
        $this->assertStringContainsString('Left before', $result['reason']);
    }

    public function test_somebody_starting_after_the_period_is_owed_nothing(): void
    {
        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-10-05');

        $this->assertSame(0.0, $result['fraction']);
        $this->assertStringContainsString('Starts after', $result['reason']);
    }

    // ── The basis is a choice, and it changes the answer ─────────────────────

    public function test_calendar_days_give_a_different_and_also_correct_answer(): void
    {
        config(['payroll.pro_rata_basis' => 'calendar']);

        $result = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-09-21');

        // 10 calendar days of 30, against 8 working days of 22 — the contract decides which.
        $this->assertSame(10, $result['days_paid']);
        $this->assertSame(30, $result['days_in_period']);
        $this->assertSame('calendar', $result['basis']);
    }

    public function test_a_weekend_start_costs_nothing_extra_on_the_working_day_basis(): void
    {
        // 26 September 2026 is a Saturday; the first payable day is Monday the 28th.
        $saturday = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-09-26');
        $monday = $this->calc()->forPeriod('2026-09-01', '2026-09-30', '2026-09-28');

        $this->assertSame($monday['days_paid'], $saturday['days_paid']);
    }

    // ── The stored date formats this module has been caught by before ────────

    public function test_a_date_stored_as_a_unix_timestamp_is_understood(): void
    {
        $person = (object) [
            'date_joined' => (string) \Illuminate\Support\Carbon::parse('2026-09-21')->timestamp,
            'date_left'   => null,
        ];

        // hr_people stores some dates as bigints; Carbon::parse on one throws, which has broken
        // this module before.
        $result = $this->calc()->forPerson($person, '2026-09-01', '2026-09-30');

        $this->assertTrue($result['partial']);
        $this->assertSame(8, $result['days_paid']);
    }

    public function test_a_person_with_no_dates_at_all_is_paid_in_full(): void
    {
        $result = $this->calc()->forPerson((object) ['date_joined' => null, 'date_left' => null], '2026-09-01', '2026-09-30');

        // Most records predate the field. Refusing to pay them would be worse than the bug.
        $this->assertFalse($result['partial']);
        $this->assertSame(1.0, $result['fraction']);
    }
}
