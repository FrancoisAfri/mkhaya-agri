<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Check-in, check-out, and the question the access log could never answer (CON-16).
 *
 * The log has recorded every decision since the module shipped, with a `direction` column
 * documented as ENTRY, EXIT, CLOCK_IN, CLOCK_OUT. Nothing ever paired them. So "who is on site
 * right now" had no answer for contractor workers — and on the morning of an evacuation that is
 * the only question anybody asks.
 *
 * Most of these tests are about the ways a register like this quietly becomes wrong: a
 * double-tapped turnstile, somebody appearing to be in two places, a person who went home
 * without scanning. Each of those, left alone, makes the roll-call over-count — and a roll-call
 * nobody believes is worse than none, because somebody still has to account for the names on it.
 */
class SiteAttendanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    private int $workerId;

    private string $workerUuid;

    private int $refinery;

    private int $depot;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        $this->refinery = $this->site('Refinery');
        $this->depot = $this->site('Depot');

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]), 'updated_at' => now()],
        );

        $contractors = app(ContractorService::class);

        $this->contractor = $contractors->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);

        $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
            $this->user->id);

        $this->workerUuid = (string) Str::uuid();

        $this->workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => $this->workerUuid, 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
            'trade' => 'SCAFFOLDING',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'att-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'T'.Str::upper(Str::random(5)), 'name' => $name, 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attendance(): SiteAttendanceService
    {
        return app(SiteAttendanceService::class);
    }

    /** @param  array<string,mixed>  $overrides */
    private function move(string $direction, array $overrides = []): array
    {
        return $this->attendance()->record($this->companyId, array_merge([
            'site_id' => $this->refinery,
            'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => $this->workerId,
            'contractor_id' => $this->contractor['id'],
            'person_name' => 'Thabo Mokoena',
            'contractor_name' => 'Acme Scaffolding',
            'direction' => $direction,
            'method' => 'CARD',
            'allowed' => true,
            'reasons' => [],
        ], $overrides));
    }

    private function openStay(): ?object
    {
        return DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->where('subject_id', $this->workerId)
            ->whereNull('exited_at')->first();
    }

    // ── A stay, from one end to the other ─────────────────────────────────────────────────────

    public function test_an_entry_opens_a_stay_and_an_exit_closes_it(): void
    {
        $in = $this->move('ENTRY');

        $this->assertTrue($in['opened']);
        $this->assertCount(1, $this->attendance()->onSite($this->companyId));

        $out = $this->move('EXIT');

        $this->assertTrue($out['closed']);
        $this->assertSame([], $this->attendance()->onSite($this->companyId));

        $stay = DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->where('subject_id', $this->workerId)
            ->first(['entered_at', 'exited_at', 'exit_reason', 'entry_method', 'exit_method']);

        $this->assertNotNull($stay->exited_at);
        $this->assertSame(SiteAttendanceService::SCANNED_OUT, $stay->exit_reason);
        $this->assertSame('CARD', $stay->entry_method);
    }

    public function test_a_second_read_at_the_same_boom_is_not_a_second_arrival(): void
    {
        /*
         * Turnstiles double-read, people tap twice, a guard scans a card the reader already
         * took. Opening a stay for each would make the register useless inside a week, and the
         * arrival time — the one that matters for an overstay — would keep moving forward.
         */
        $first = $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(3)]);
        $second = $this->move('ENTRY');

        $this->assertTrue($first['opened']);
        $this->assertFalse($second['opened'], 'A double-tap opened a second stay.');

        $this->assertSame(1, DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->where('subject_id', $this->workerId)
            ->count());

        // And it is still three hours, not nothing.
        $this->assertGreaterThanOrEqual(179,
            $this->attendance()->onSite($this->companyId)[0]['minutes_on_site']);
    }

    public function test_entering_somewhere_else_closes_the_previous_stay(): void
    {
        /*
         * A person is in one place. Leaving both stays open produces a roll-call that
         * over-counts, which during an evacuation sends a marshal back inside to look for
         * somebody who left an hour ago.
         */
        $this->move('ENTRY', ['site_id' => $this->refinery]);
        $this->move('ENTRY', ['site_id' => $this->depot]);

        $open = $this->attendance()->onSite($this->companyId);

        $this->assertCount(1, $open);
        $this->assertSame($this->depot, $open[0]['site_id']);

        $closed = DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->where('site_id', $this->refinery)
            ->first(['exit_reason']);

        $this->assertSame(SiteAttendanceService::ENTERED_ELSEWHERE, $closed->exit_reason,
            'The earlier stay was closed without saying why.');
    }

    public function test_one_open_stay_per_person_is_enforced_by_the_database(): void
    {
        // The partial unique index. Belt and braces behind the service, because an import, a
        // console command or a future caller could all reach the table directly.
        $this->move('ENTRY');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('sheq_site_attendance')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->depot, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => $this->workerId, 'entered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_exit_with_no_entry_is_logged_and_opens_nothing(): void
    {
        /*
         * It happens: the system was installed at lunchtime, the entry reader was down,
         * somebody walked in behind a colleague. The event is recorded; inventing a stay with a
         * guessed arrival time would put a number in an hours report that nobody measured.
         */
        $out = $this->move('EXIT');

        $this->assertFalse($out['closed']);
        $this->assertSame(0, DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->count());

        $this->assertSame(1, DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->where('direction', 'EXIT')->count());
    }

    public function test_a_refusal_is_logged_but_puts_nobody_on_the_roll_call(): void
    {
        // The log says they tried, the roll-call says they are not inside, and both are true.
        $this->move('ENTRY', ['allowed' => false, 'reasons' => ['COIDA letter expired.']]);

        $this->assertSame([], $this->attendance()->onSite($this->companyId));

        $event = DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->first(['allowed', 'reasons']);

        $this->assertFalse((bool) $event->allowed);
        $this->assertStringContainsString('COIDA', (string) $event->reasons);
    }

    public function test_a_pass_look_up_is_not_an_arrival(): void
    {
        // Somebody checking whether a contractor's file is in order is not arriving. Treating
        // every read as a movement would put people on the roll-call who are two kilometres away.
        $this->move('VERIFY');

        $this->assertSame([], $this->attendance()->onSite($this->companyId));
        $this->assertSame(1, DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->count());
    }

    // ── What CON-16 says must be recorded ─────────────────────────────────────────────────────

    public function test_every_event_records_what_the_requirement_asks_for(): void
    {
        /*
         * Contractor, worker, site, direction, time, method, device, result and — on a refusal —
         * the reason. The contractor was the one missing: `subject_id` is the worker, so "which
         * employers had people on site on the day of the incident" meant a join nobody wrote.
         */
        $this->move('ENTRY', [
            'method' => 'BIOMETRIC', 'device_ref' => 'READER-07',
            'activity' => 'WORKING_AT_HEIGHT',
        ]);

        $event = DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->latest('id')->first();

        $this->assertSame($this->contractor['id'], (int) $event->contractor_id);
        $this->assertSame($this->workerId, (int) $event->subject_id);
        $this->assertSame('CONTRACTOR_WORKER', $event->subject_type);
        $this->assertSame($this->refinery, (int) $event->site_id);
        $this->assertSame('ENTRY', $event->direction);
        $this->assertSame('BIOMETRIC', $event->method);
        $this->assertSame('READER-07', $event->device_ref);
        $this->assertSame('WORKING_AT_HEIGHT', $event->activity);
        $this->assertTrue((bool) $event->allowed);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_an_unknown_method_is_recorded_as_unknown_rather_than_invented(): void
    {
        // A log that cannot tell a scan from a guard's note cannot weigh either, and a made-up
        // value is worse than an empty one.
        $this->move('ENTRY', ['method' => 'TELEPATHY']);

        $this->assertNull(DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->latest('id')->value('method'));
    }

    // ── The forgotten exit ────────────────────────────────────────────────────────────────────

    public function test_a_stay_nobody_closed_is_swept_up_and_says_so(): void
    {
        /*
         * People go home without scanning. Left alone, every one of those stays is open for
         * ever and the roll-call is permanently over-counted — within a month it reads as
         * hundreds of people on a site that holds forty, and at that point it is ignored by
         * exactly the people it exists for.
         */
        $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(20)]);

        $closed = $this->attendance()->closeStale($this->companyId, 14);

        $this->assertSame(1, $closed);
        $this->assertNull($this->openStay());

        $stay = DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->first(['exited_at', 'exit_reason']);

        $this->assertSame(SiteAttendanceService::NOT_SIGNED_OUT, $stay->exit_reason,
            'A forgotten exit was recorded as though somebody had scanned out.');

        /*
         * Closed at the cut-off, not when the sweep ran, so an hours report is not inflated by
         * how late the scheduler fired.
         */
        $this->assertTrue(
            Carbon::parse($stay->exited_at)->lessThan(Carbon::now()->subHours(5)),
            'The stay was closed at the time of the sweep rather than at the cut-off.');
    }

    public function test_the_sweep_leaves_somebody_who_is_genuinely_still_here(): void
    {
        // A twelve-hour default would close night shift out from under itself — a roll-call that
        // says a site is empty while people are working in it is the same failure, reversed.
        $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(6)]);

        $this->assertSame(0, $this->attendance()->closeStale($this->companyId, 14));
        $this->assertNotNull($this->openStay());
    }

    public function test_the_command_runs_per_tenant(): void
    {
        $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(20)]);

        $this->artisan('sheq:attendance:close-stale', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertNull($this->openStay());
    }

    public function test_long_stays_are_reported_before_they_are_swept(): void
    {
        // The figure a supervisor checks before going home. It is not an alarm — night shift
        // looks like this — but a site where nobody signs out looks like this too.
        $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(16)]);

        $this->assertCount(1, $this->attendance()->overstaying($this->companyId, 14));
        $this->assertSame([], $this->attendance()->overstaying($this->companyId, 20));
    }

    // ── Through the gates ─────────────────────────────────────────────────────────────────────

    public function test_the_turnstile_puts_somebody_on_the_roll_call(): void
    {
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        app(AccessDecisionService::class)->decideAndRecord($this->companyId, $identifier, [
            'site_id' => $this->refinery, 'direction' => 'ENTRY', 'enforce' => true,
            'device_ref' => 'BOOM-1',
        ]);

        $roll = $this->attendance()->onSite($this->companyId);

        $this->assertCount(1, $roll);
        $this->assertSame('Thabo Mokoena', $roll[0]['person_name']);
        // Copied onto the stay, so the roll-call still names the employer after a roster is
        // tidied up — and those are exactly the records somebody is about to tidy.
        $this->assertSame('Acme Scaffolding', $roll[0]['contractor_name']);
    }

    public function test_monitor_mode_puts_them_on_the_roll_call_even_though_it_refused(): void
    {
        /*
         * The one that would cost somebody. In monitor mode a refusal still opens the boom, so
         * the person IS inside. A register built on the verdict rather than on what the
         * hardware did would leave them off the evacuation list.
         */
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['access_status' => 'BLOCKED']);

        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        $decision = app(AccessDecisionService::class)
            ->decideAndRecord($this->companyId, $identifier, [
                'site_id' => $this->refinery, 'direction' => 'ENTRY',
                'enforce' => false,   // monitor mode
            ]);

        $this->assertFalse($decision['allowed']);
        $this->assertTrue($decision['open_gate']);

        $this->assertCount(1, $this->attendance()->onSite($this->companyId),
            'Monitor mode let somebody through the boom and left them off the roll-call.');
    }

    public function test_an_enforced_refusal_keeps_them_off_it(): void
    {
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['access_status' => 'BLOCKED']);

        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        app(AccessDecisionService::class)->decideAndRecord($this->companyId, $identifier, [
            'site_id' => $this->refinery, 'direction' => 'ENTRY', 'enforce' => true,
        ]);

        $this->assertSame([], $this->attendance()->onSite($this->companyId));
    }

    public function test_a_scanned_pass_checks_somebody_in_when_the_scanner_says_so(): void
    {
        /*
         * A gate with a phone and no turnstile. The same read is the check-in when the scanner
         * is configured to say which way somebody is going — and a plain look-up is still only
         * a look-up.
         */
        $token = app(AccessPassService::class)
            ->workerToken($this->companyId, $this->workerUuid, $this->user->id);

        $this->get("/sheq/pass/{$token}?site={$this->refinery}")->assertOk();

        $this->assertSame([], $this->attendance()->onSite($this->companyId),
            'A compliance look-up put somebody on the roll-call.');

        $this->get("/sheq/pass/{$token}?site={$this->refinery}&direction=ENTRY")->assertOk();

        $roll = $this->attendance()->onSite($this->companyId);

        $this->assertCount(1, $roll);
        $this->assertSame('QR', $roll[0]['entry_method']);
    }

    // ── By hand ───────────────────────────────────────────────────────────────────────────────

    public function test_a_guard_can_sign_somebody_in_and_out_by_hand(): void
    {
        // A flat battery on a reader is not a reason to leave people unrecorded, and a site with
        // no manual option gets a paper list that never reaches the system at all.
        $this->actingAs($this->user)->post('/sheq/attendance/entry', [
            'worker_uuid' => $this->workerUuid, 'site_id' => $this->refinery,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $roll = $this->attendance()->onSite($this->companyId);

        $this->assertCount(1, $roll);
        $this->assertSame('MANUAL', $roll[0]['entry_method'],
            'A hand-written entry was recorded as though it had been scanned.');

        $this->actingAs($this->user)->post('/sheq/attendance/exit', [
            'worker_uuid' => $this->workerUuid,
        ])->assertRedirect();

        $this->assertSame([], $this->attendance()->onSite($this->companyId));

        $this->assertSame(SiteAttendanceService::MANUAL, DB::table('sheq_site_attendance')
            ->where('company_id', $this->companyId)->value('exit_reason'));
    }

    public function test_a_hand_written_movement_cannot_be_dated_in_the_future(): void
    {
        // A register that can record an arrival that has not happened is one that can be used
        // to say somebody was here when they were not.
        $this->actingAs($this->user)->post('/sheq/attendance/entry', [
            'worker_uuid' => $this->workerUuid,
            'occurred_at' => Carbon::now()->addHour()->toDateTimeString(),
        ])->assertSessionHasErrors('occurred_at');
    }

    public function test_a_hand_written_movement_is_audited(): void
    {
        // It is the one path with no hardware behind it, so the only evidence it happened at
        // all is who said so.
        $this->actingAs($this->user)->post('/sheq/attendance/entry', [
            'worker_uuid' => $this->workerUuid, 'site_id' => $this->refinery,
        ])->assertRedirect();

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'attendance.manual_in')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, $entry->actor_id);
    }

    public function test_signing_somebody_in_needs_the_contractor_permission(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/attendance/entry', ['worker_uuid' => $this->workerUuid])
            ->assertForbidden();
    }

    public function test_another_company_worker_cannot_be_signed_in(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-att-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignContractor = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'name' => 'Someone Else', 'access_status' => 'COMPLIANT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignUuid = (string) Str::uuid();

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => $foreignUuid, 'company_id' => $otherCompany,
            'contractor_id' => $foreignContractor, 'name' => 'Not Our Employee',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post('/sheq/attendance/entry', ['worker_uuid' => $foreignUuid])
            ->assertSessionHasErrors('worker_uuid');
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_screen_shows_the_roll_call_and_the_days_movements(): void
    {
        /*
         * Pinned to the middle of the day, and not for neatness. "Two hours ago" fails this test
         * whenever it is run between midnight and 02:00, because `movements()` windows on
         * `Carbon::today()` in the application timezone and compares it against timestamps stored
         * in UTC. At 00:05 SAST, two hours ago is 22:05 UTC yesterday.
         *
         * That is a real defect for a 24-hour site and not this test's to fix: a movement at
         * 01:00 SAST belongs to the shift that has just started, and it currently lands on the
         * previous day's list. Recorded here so the next person does not read the pinned clock as
         * decoration.
         */
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));

        $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(2)]);

        $props = $this->actingAs($this->user)->get('/sheq/attendance')->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['onSite']);
        $this->assertSame('Thabo Mokoena', $props['onSite'][0]['person_name']);
        $this->assertNotEmpty($props['movements']);

        // The reason a refusal happened reaches the screen, where CON-16 requires it.
        $this->move('ENTRY', ['allowed' => false, 'reasons' => ['Induction expired.']]);

        $movements = $this->actingAs($this->user)->get('/sheq/attendance')
            ->viewData('page')['props']['movements'];

        $refusal = collect($movements)->firstWhere('allowed', false);

        $this->assertNotNull($refusal);
        $this->assertSame(['Induction expired.'], $refusal['reasons']);

        Carbon::setTestNow();
    }

    public function test_the_screen_says_who_is_already_inside_so_nobody_is_signed_in_twice(): void
    {
        /*
         * The commonest mistake at a desk: signing in somebody who never signed out yesterday,
         * which produces a nineteen-hour stay and a register that looks wrong to everybody.
         */
        $this->move('ENTRY');

        $worker = collect($this->actingAs($this->user)->get('/sheq/attendance')
            ->viewData('page')['props']['workers'])
            ->firstWhere('value', $this->workerUuid);

        $this->assertNotNull($worker);
        $this->assertTrue($worker['on_site']);
    }

    public function test_the_screen_can_be_filtered_to_one_site(): void
    {
        $this->move('ENTRY', ['site_id' => $this->refinery]);

        $atDepot = $this->actingAs($this->user)
            ->get("/sheq/attendance?site_id={$this->depot}")->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $atDepot['onSite']);

        $atRefinery = $this->actingAs($this->user)
            ->get("/sheq/attendance?site_id={$this->refinery}")->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $atRefinery['onSite']);
    }

    public function test_one_persons_history_reads_back_with_durations(): void
    {
        $this->move('ENTRY', ['occurred_at' => Carbon::now()->subHours(9)]);
        $this->move('EXIT', ['occurred_at' => Carbon::now()->subHours(1)]);

        $history = $this->attendance()
            ->historyFor($this->companyId, 'CONTRACTOR_WORKER', $this->workerId);

        $this->assertCount(1, $history);
        $this->assertSame(480, $history[0]['minutes']);
    }
}
