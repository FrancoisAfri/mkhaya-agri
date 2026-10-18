<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Site-local day boundaries (REG-FIX-6).
 *
 * The platform half of this was already fixed: `config/database.php` pins the session zone to
 * `app.timezone`, so a timestamp written by Laravel and read back by Postgres means the same
 * moment. SHEQ-ADR-005 records it.
 *
 * What was left is the half nobody had noticed, because it does not look like a bug. "Movements
 * today" was computed as UTC midnight to UTC midnight and compared against stored UTC timestamps.
 * That is internally consistent and still wrong, because the day a supervisor means is the day at
 * their gate: in SAST the UTC window starts two hours late, so a night shift entering at 01:00
 * local lands on the previous day's list and the first two hours of every morning are missing from
 * "today". A report run at 07:00 omits the night shift entirely and the list it produces is
 * ordered, plausible, and short by exactly the number of people who came in before 02:00.
 *
 * The clock is pinned in every test here. Without that these pass or fail depending on the hour
 * the suite runs at — which is how the defect survived being found twice.
 */
class SiteTimezoneTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── The helper ────────────────────────────────────────────────────────────────────────────

    public function test_a_sites_day_starts_at_local_midnight_not_utc_midnight(): void
    {
        $site = $this->site('Africa/Johannesburg');

        [$from, $to] = Timestamps::siteDay($site, Carbon::parse('2026-10-09 12:00:00', 'UTC'),
            $this->companyId);

        // Local midnight on the 9th in SAST is 22:00 UTC on the 8th.
        $this->assertSame('2026-10-08 22:00:00', $from->toDateTimeString(),
            'The day begins at UTC midnight, so the first two hours of every SAST morning fall '
            .'outside it.');
        $this->assertSame('2026-10-09 21:59:59', $to->toDateTimeString());
        $this->assertSame('UTC', $from->format('e'),
            'The window is not in UTC, so it cannot be compared against the stored column.');
    }

    public function test_a_site_in_another_zone_gets_its_own_day(): void
    {
        $lagos = $this->site('Africa/Lagos');      // UTC+1
        $nairobi = $this->site('Africa/Nairobi');  // UTC+3

        [$lagosFrom] = Timestamps::siteDay($lagos, Carbon::parse('2026-10-09 12:00:00', 'UTC'),
            $this->companyId);
        [$nairobiFrom] = Timestamps::siteDay($nairobi, Carbon::parse('2026-10-09 12:00:00', 'UTC'),
            $this->companyId);

        $this->assertSame('2026-10-08 23:00:00', $lagosFrom->toDateTimeString());
        $this->assertSame('2026-10-08 21:00:00', $nairobiFrom->toDateTimeString());

        $this->assertTrue($nairobiFrom->lt($lagosFrom),
            'Two sites in different zones get the same day boundary, so a group operating across '
            .'borders reports both against one of them.');
    }

    public function test_the_fallback_matches_the_columns_own_default(): void
    {
        /*
         * `sheq_sites.timezone` is NOT NULL and defaults to Africa/Johannesburg, so in practice
         * every site has a usable zone. The fallback exists for the cases the column cannot cover
         * — a blank string, an unrecognised value, a site that has been deleted — and it has to
         * agree with the column's default, or a site created by the database and a site resolved
         * by this helper would disagree about when their day starts.
         */
        $default = DB::selectOne("select column_default from information_schema.columns
            where table_name = 'sheq_sites' and column_name = 'timezone'")->column_default ?? '';

        $this->assertStringContainsString(Timestamps::FALLBACK_ZONE, (string) $default,
            'The helper\'s fallback zone and the column\'s default have diverged, so two sites '
            .'configured identically can report different days.');
    }

    public function test_a_blank_zone_falls_back_rather_than_throwing(): void
    {
        /*
         * Guessing UTC would put an unconfigured site's day boundary two hours out, which is the
         * exact failure this helper exists to prevent — so the fallback is the zone the product
         * ships into, not Greenwich.
         */
        $site = $this->site('');

        $this->assertSame(Timestamps::FALLBACK_ZONE,
            Timestamps::zoneFor($site, $this->companyId));

        [$from] = Timestamps::siteDay($site, Carbon::parse('2026-10-09 12:00:00', 'UTC'),
            $this->companyId);

        $this->assertSame('2026-10-08 22:00:00', $from->toDateTimeString());
    }

    public function test_a_site_that_no_longer_exists_does_not_break_a_report(): void
    {
        // A historic report over a deleted site still has to render.
        $this->assertSame(Timestamps::FALLBACK_ZONE,
            Timestamps::zoneFor(999999999, $this->companyId));
    }

    public function test_an_unrecognised_zone_string_does_not_break_the_screen(): void
    {
        /*
         * Somebody types a city name into a settings field. `Carbon::parse` throws on an
         * unrecognised zone, so without this guard an attendance screen returns a 500 because a
         * site is misconfigured.
         */
        $site = $this->site('Johannesburg');

        $this->assertSame(Timestamps::FALLBACK_ZONE,
            Timestamps::zoneFor($site, $this->companyId));

        [$from, $to] = Timestamps::siteDay($site, null, $this->companyId);

        $this->assertTrue($from->lt($to));
    }

    public function test_a_range_includes_both_ends(): void
    {
        // Somebody choosing "1st to 7th" expects the 7th. An exclusive end silently loses a day
        // of data every single time it is used.
        $site = $this->site('Africa/Johannesburg');

        [$from, $to] = Timestamps::siteRange($site,
            Carbon::parse('2026-10-01', 'UTC'), Carbon::parse('2026-10-07', 'UTC'),
            $this->companyId);

        $this->assertSame('2026-09-30 22:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-07 21:59:59', $to->toDateTimeString());
    }

    public function test_a_backwards_range_is_corrected_rather_than_returning_nothing(): void
    {
        $site = $this->site('Africa/Johannesburg');

        [$from, $to] = Timestamps::siteRange($site,
            Carbon::parse('2026-10-07', 'UTC'), Carbon::parse('2026-10-01', 'UTC'),
            $this->companyId);

        $this->assertTrue($from->lt($to),
            'A from-date after its to-date returns an empty window, which reads as "nothing '
            .'happened" rather than as a mistyped filter.');
    }

    public function test_another_tenants_site_zone_is_not_read(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        $foreign = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'code' => 'F'.Str::upper(Str::random(5)), 'name' => 'Theirs',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Nairobi',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(Timestamps::FALLBACK_ZONE,
            Timestamps::zoneFor($foreign, $this->companyId),
            'One tenant read another tenant\'s site configuration.');
    }

    // ── REG-FIX-6, the structural half ────────────────────────────────────────────────────────

    public function test_no_sheq_column_stores_a_timestamp_without_an_offset(): void
    {
        /*
         * 352 columns across 124 tables carried `timestamp without time zone`, beside 111 that
         * already carried the offset. A naive timestamp has no offset, so what it means depends
         * on who wrote it and who is reading — and in a module computing statutory deadlines,
         * access expiries and injury rates, "depends on who is reading" is not a property
         * anybody wants.
         *
         * A guard rather than a one-off check: the conversion is done, and the way it comes
         * undone is a new migration adding `->timestamp()` instead of `->timestampTz()`, one
         * column at a time, invisibly.
         */
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-specific.');
        }

        $naive = DB::select("
            select c.table_name, c.column_name
            from information_schema.columns c
            join information_schema.tables t
              on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = current_schema()
              and c.table_name like 'sheq\\_%'
              and c.data_type = 'timestamp without time zone'
              and t.table_type = 'BASE TABLE'
            order by c.table_name, c.column_name
        ");

        $names = array_map(fn ($row) => $row->table_name.'.'.$row->column_name, $naive);

        $this->assertSame([], $names,
            'These SHEQ columns store a timestamp with no offset, so their meaning depends on '
            .'the session that reads them: '.implode(', ', array_slice($names, 0, 20))
            .(count($names) > 20 ? ' and '.(count($names) - 20).' more' : '')
            .'. Use timestampTz() in the migration that added them.');
    }

    public function test_an_instant_survives_being_read_through_a_different_session_zone(): void
    {
        /*
         * The property the conversion exists to give, asserted end to end rather than on the
         * catalogue. The application's connection is pinned to +00:00 and a raw psql session on
         * the same database runs Africa/Johannesburg — so before this, the same stored value
         * meant two different moments depending on who asked.
         */
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-specific.');
        }

        $siteId = $this->site('Africa/Johannesburg');

        DB::table('sheq_sites')->where('id', $siteId)
            ->update(['created_at' => '2026-03-04 14:30:00']);

        $asUtc = DB::selectOne("
            select (created_at at time zone 'UTC')::text as t from sheq_sites where id = ?",
            [$siteId])->t;

        $asSast = DB::selectOne("
            select (created_at at time zone 'Africa/Johannesburg')::text as t
            from sheq_sites where id = ?", [$siteId])->t;

        // Same instant, two clocks. Before the conversion both reads returned 14:30, because
        // there was no offset to convert from and the value was simply a string.
        $this->assertStringStartsWith('2026-03-04 14:30:00', $asUtc);
        $this->assertStringStartsWith('2026-03-04 16:30:00', $asSast,
            'The stored value has no offset, so reading it in another zone returns the same '
            .'wall-clock rather than the same moment.');
    }

    // ── The defect it fixes ───────────────────────────────────────────────────────────────────

    public function test_the_night_shift_appears_on_the_day_it_worked(): void
    {
        /*
         * The bug, stated as a scenario. 01:00 SAST on the 9th is 23:00 UTC on the 8th, so a
         * UTC-day window for the 9th excludes it and a window for the 8th includes it — a person
         * who came to work on Friday morning shows up on Thursday's report.
         */
        $site = $this->site('Africa/Johannesburg');

        // A supervisor looking at the screen at 07:00 local on the 9th.
        Carbon::setTestNow(Carbon::parse('2026-10-09 05:00:00', 'UTC'));

        $this->accessEvent($site, Carbon::parse('2026-10-08 23:00:00', 'UTC'), 'Night Shift');
        $this->accessEvent($site, Carbon::parse('2026-10-09 04:30:00', 'UTC'), 'Day Shift');

        $names = array_column(
            app(SiteAttendanceService::class)->movements($this->companyId, null, $site),
            'person_name');

        $this->assertContains('Night Shift', $names,
            'Somebody who entered at 01:00 local is missing from today\'s movements, so a daily '
            .'report run at 07:00 omits the night shift entirely.');
        $this->assertContains('Day Shift', $names);
    }

    public function test_yesterdays_night_shift_is_not_counted_as_today(): void
    {
        $site = $this->site('Africa/Johannesburg');

        Carbon::setTestNow(Carbon::parse('2026-10-09 05:00:00', 'UTC'));

        // 23:00 UTC on the 7th is 01:00 local on the 8th — the previous day at the gate.
        $this->accessEvent($site, Carbon::parse('2026-10-07 23:00:00', 'UTC'), 'Last Night');

        $names = array_column(
            app(SiteAttendanceService::class)->movements($this->companyId, null, $site),
            'person_name');

        $this->assertNotContains('Last Night', $names,
            'The window is too wide, so yesterday\'s night shift is counted twice.');
    }

    public function test_the_last_hour_of_the_local_day_is_still_today(): void
    {
        $site = $this->site('Africa/Johannesburg');

        Carbon::setTestNow(Carbon::parse('2026-10-09 20:00:00', 'UTC')); // 22:00 local

        // 23:30 local on the 9th, which is 21:30 UTC — still the 9th at the gate.
        $this->accessEvent($site, Carbon::parse('2026-10-09 21:30:00', 'UTC'), 'Late Finish');

        $names = array_column(
            app(SiteAttendanceService::class)->movements($this->companyId, null, $site),
            'person_name');

        $this->assertContains('Late Finish', $names,
            'The last ninety minutes of the local day fall outside "today", so a late shift '
            .'disappears from the register it is meant to appear on.');
    }

    public function test_two_sites_in_different_zones_report_their_own_days(): void
    {
        $joburg = $this->site('Africa/Johannesburg');
        $nairobi = $this->site('Africa/Nairobi');

        Carbon::setTestNow(Carbon::parse('2026-10-09 05:00:00', 'UTC'));

        // 22:30 UTC on the 8th: already the 9th in Nairobi (01:30) and still the 9th in
        // Johannesburg (00:30). Both should see it.
        $this->accessEvent($joburg, Carbon::parse('2026-10-08 22:30:00', 'UTC'), 'JHB Early');
        $this->accessEvent($nairobi, Carbon::parse('2026-10-08 22:30:00', 'UTC'), 'NBO Early');

        $service = app(SiteAttendanceService::class);

        $this->assertContains('JHB Early',
            array_column($service->movements($this->companyId, null, $joburg), 'person_name'));
        $this->assertContains('NBO Early',
            array_column($service->movements($this->companyId, null, $nairobi), 'person_name'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function site(string $timezone): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'T'.Str::upper(Str::random(5)), 'name' => 'Site '.($timezone ?: 'blank'),
            'site_type' => 'CONSTRUCTION', 'timezone' => $timezone,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function accessEvent(int $siteId, Carbon $at, string $name): void
    {
        DB::table('sheq_access_events')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $siteId, 'subject_type' => 'EMPLOYEE',
            'person_name' => $name, 'direction' => 'ENTRY', 'method' => 'QR',
            'allowed' => true,
            'occurred_at' => $at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
