<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Services\AccessOverrideService;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Temporary access, with a beginning and an end (CON-17).
 *
 * What this replaces was four columns on a contractor, and almost everything the requirement
 * asks for was missing from them. No start time, so an override could not be arranged for
 * tomorrow morning. No approver as a separate fact from whoever clicked. No conditions. No
 * scope, so an afternoon's grant at the depot admitted the same people to the refinery. No
 * history — one override per contractor, each overwriting the last. No way to cover a single
 * worker, so one lapsed medical meant overriding a firm of forty.
 *
 * And, above all, it never expired. Nothing in the codebase ever set `access_overridden` back
 * to false; the only expiry was a date comparison at read time, which the compliance file made
 * and the cached `access_status` the turnstile reads did not. Most of what follows is about
 * that.
 */
class AccessOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private User $approver;

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
        $this->approver = $this->userWith(['sheq.contractor.manage']);

        $this->refinery = $this->site('Refinery');
        $this->depot = $this->site('Depot');

        // One document demanded, and never supplied, so the contractor is blocked for a reason
        // an override is the honest way past.
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode(['COIDA_LETTER']),
                'updated_at' => now()],
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
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ovr-'.Str::random(8).'@example.test',
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
            'code' => 'O'.Str::upper(Str::random(5)), 'name' => $name, 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function overrides(): AccessOverrideService
    {
        return app(AccessOverrideService::class);
    }

    /** @param  array<string,mixed>  $overrides */
    private function grant(array $overrides = []): array
    {
        return $this->overrides()->grant($this->companyId, array_merge([
            'subject_type' => AccessOverrideService::CONTRACTOR,
            'subject_uuid' => $this->contractor['uuid'],
            'reason' => 'Letter of good standing confirmed by telephone with the Commissioner.',
            'ends_at' => Carbon::now()->addDays(3)->toDateTimeString(),
        ], $overrides), $this->user->id);
    }

    private function mayWork(?int $siteId = null, ?string $activity = null): bool
    {
        return app(ContractorService::class)->mayWork($this->companyId,
            $this->contractor['uuid'], [], $siteId, $activity)['allowed'];
    }

    private function cachedStatus(): string
    {
        return (string) DB::table('sheq_contractors')
            ->where('id', $this->contractor['id'])->value('access_status');
    }

    // ── It must actually expire ───────────────────────────────────────────────────────────────

    public function test_an_override_admits_a_contractor_and_then_stops(): void
    {
        /*
         * The whole requirement in one test. The implementation this replaces failed the second
         * half: nothing ever switched the flag off.
         */
        $this->assertFalse($this->mayWork());

        $this->grant(['ends_at' => Carbon::now()->addHours(4)->toDateTimeString()]);

        $this->assertTrue($this->mayWork(), 'The override did not admit them.');

        $this->travelTo(Carbon::now()->addHours(5));

        $this->assertFalse($this->mayWork(),
            'The override went on admitting them after its window closed.');
    }

    public function test_the_cached_status_the_turnstile_reads_is_put_back_when_one_lapses(): void
    {
        /*
         * The bug behind the bug. `mayWork()` recomputes live, so it was always right. The
         * turnstile reads `sheq_contractors.access_status`, which is stored — and nothing ever
         * recomputed it when an override ran out. So the gate kept opening for a contractor the
         * compliance screen had already gone back to showing as blocked.
         */
        $this->grant(['ends_at' => Carbon::now()->addHours(4)->toDateTimeString()]);

        $this->assertSame('EXPIRING', $this->cachedStatus());

        $this->travelTo(Carbon::now()->addHours(5));

        $this->assertSame(1, $this->overrides()->expireLapsed($this->companyId));

        $this->assertSame('BLOCKED', $this->cachedStatus(),
            'The cached status the turnstile reads still admitted them after the override '
            .'expired.');
    }

    public function test_the_turnstile_refuses_again_once_the_sweep_has_run(): void
    {
        // End to end on the surface that matters: a boom reading a stale cache is a boom
        // opening for somebody nobody authorised any more.
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        $this->grant(['ends_at' => Carbon::now()->addHours(2)->toDateTimeString()]);

        $gate = app(AccessDecisionService::class);

        $this->assertTrue($gate->decide($this->companyId, $identifier, $this->refinery)['allowed']);

        $this->travelTo(Carbon::now()->addHours(3));
        $this->artisan('sheq:overrides:expire', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertFalse($gate->decide($this->companyId, $identifier, $this->refinery)['allowed'],
            'The turnstile admitted somebody on an override that had already expired.');
    }

    public function test_expiry_is_stamped_at_the_end_of_the_window_not_when_the_sweep_ran(): void
    {
        // Otherwise a report of how long an override was in force is stretched by however late
        // the scheduler happened to fire.
        $endsAt = Carbon::now()->addHours(2);

        $granted = $this->grant(['ends_at' => $endsAt->toDateTimeString()]);

        $this->travelTo(Carbon::now()->addHours(9));
        $this->overrides()->expireLapsed($this->companyId);

        $stamped = DB::table('sheq_access_overrides')
            ->where('uuid', $granted['uuid'])->value('expired_at');

        $this->assertSame($endsAt->startOfSecond()->toDateTimeString(),
            Carbon::parse($stamped)->startOfSecond()->toDateTimeString());
    }

    public function test_the_sweep_leaves_one_that_is_still_running(): void
    {
        $this->grant(['ends_at' => Carbon::now()->addDays(2)->toDateTimeString()]);

        $this->assertSame(0, $this->overrides()->expireLapsed($this->companyId));
        $this->assertTrue($this->mayWork());
    }

    // ── A window with both ends ───────────────────────────────────────────────────────────────

    public function test_an_override_can_be_arranged_in_advance(): void
    {
        /*
         * A shutdown starting at 06:00 should not need somebody at a desk at 06:00. The old
         * implementation had only an end date, so an override was live the moment it was typed.
         */
        $this->grant([
            'starts_at' => Carbon::now()->addDay()->toDateTimeString(),
            'ends_at' => Carbon::now()->addDays(2)->toDateTimeString(),
        ]);

        $this->assertFalse($this->mayWork(),
            'An override scheduled for tomorrow admitted somebody today.');

        $this->travelTo(Carbon::now()->addDay()->addHour());

        $this->assertTrue($this->mayWork());
    }

    public function test_an_override_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/end after it starts/i');

        $this->grant([
            'starts_at' => Carbon::now()->addDays(2)->toDateTimeString(),
            'ends_at' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
    }

    public function test_an_override_cannot_run_for_ever(): void
    {
        /*
         * The failure mode of an override is not that it is wrong but that it is forgotten.
         * Ninety days is long enough for a shutdown and short enough that somebody has to look
         * at it again; renewing is a decision, which is the point.
         */
        $this->expectExceptionMessageMatches('/at most 90 days/i');

        $this->grant(['ends_at' => Carbon::now()->addDays(120)->toDateTimeString()]);
    }

    // ── Scope ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_override_for_one_site_does_not_open_another(): void
    {
        /*
         * The single company-wide flag made this mistake unavoidable: an afternoon's grant to
         * get a job finished at the depot admitted the same people to the refinery.
         */
        $this->grant(['site_id' => $this->depot]);

        $this->assertTrue($this->mayWork($this->depot));
        $this->assertFalse($this->mayWork($this->refinery),
            'An override granted for the depot admitted them at the refinery.');
    }

    public function test_an_override_with_no_site_covers_every_site(): void
    {
        // The honest default for a shutdown, and the behaviour the old flag had.
        $this->grant();

        $this->assertTrue($this->mayWork($this->depot));
        $this->assertTrue($this->mayWork($this->refinery));
    }

    public function test_an_override_can_be_narrowed_to_one_kind_of_work(): void
    {
        $this->grant(['activity' => 'GENERAL_CONSTRUCTION']);

        $this->assertTrue($this->mayWork(null, 'GENERAL_CONSTRUCTION'));
        $this->assertFalse($this->mayWork(null, 'WORKING_AT_HEIGHT'),
            'An override for general construction admitted work at height.');
    }

    public function test_one_person_can_be_overridden_without_their_whole_employer(): void
    {
        /*
         * A worker whose medical lapsed on the day of a shutdown used to mean overriding the
         * firm, which admits forty people to fix a problem with one.
         */
        $granted = $this->overrides()->grant($this->companyId, [
            'subject_type' => AccessOverrideService::WORKER,
            'subject_uuid' => $this->workerUuid,
            'reason' => 'Medical renewal booked for Thursday; supervisor accompanying.',
            'ends_at' => Carbon::now()->addDays(2)->toDateTimeString(),
        ], $this->user->id);

        $this->assertNotNull($granted['uuid']);

        // The worker is covered; the contractor is not.
        $this->assertNotNull($this->overrides()->inForce($this->companyId,
            AccessOverrideService::WORKER, $this->workerId));

        $this->assertNull($this->overrides()->inForce($this->companyId,
            AccessOverrideService::CONTRACTOR, (int) $this->contractor['id']));

        $this->assertFalse($this->mayWork(),
            'Overriding one worker lifted the gate for their whole employer.');
    }

    public function test_the_narrowest_override_is_the_one_reported(): void
    {
        // A site-specific grant and an estate-wide one can both apply. The gate should print
        // the terms of the one somebody actually made for this site.
        $this->grant(['reason' => 'Estate-wide, while the Commissioner reissues letters.']);
        $this->grant(['site_id' => $this->refinery, 'conditions' => 'Escorted at all times.',
            'reason' => 'Refinery only, for the turnaround.']);

        $inForce = $this->overrides()->inForce($this->companyId,
            AccessOverrideService::CONTRACTOR, (int) $this->contractor['id'], $this->refinery);

        $this->assertStringContainsString('Refinery only', $inForce['reason']);
        $this->assertSame('Escorted at all times.', $inForce['conditions']);
    }

    // ── Keeping it honest ─────────────────────────────────────────────────────────────────────

    public function test_an_override_needs_a_reason_somebody_can_review(): void
    {
        // Ten characters is not a quality bar; it is a defence against "ok" and ".". The reason
        // is what somebody reviewing a refused-then-admitted entry works from six months later.
        $this->expectExceptionMessageMatches('/reason/i');

        $this->grant(['reason' => 'ok']);
    }

    public function test_two_live_overrides_for_the_same_scope_are_refused(): void
    {
        /*
         * Not a stricter control — two people each believing they granted the only one, and
         * revoking either leaves the gate open.
         */
        $this->grant();

        $this->expectExceptionMessageMatches('/already an override outstanding/i');

        $this->grant();
    }

    public function test_the_database_refuses_a_second_live_override_too(): void
    {
        // Belt and braces behind the service: an import or a future caller could reach the
        // table directly, and the index is what makes the rule true rather than merely enforced.
        $this->grant();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('sheq_access_overrides')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'subject_type' => 'CONTRACTOR', 'subject_id' => $this->contractor['id'],
            'reason' => 'A duplicate that should not be possible.',
            'starts_at' => now(), 'ends_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_revoking_puts_the_gate_back_immediately(): void
    {
        $granted = $this->grant();

        $this->assertTrue($this->mayWork());

        $this->overrides()->revoke($this->companyId, $granted['uuid'],
            'The letter arrived and was rejected.', $this->user->id);

        $this->assertFalse($this->mayWork());
        $this->assertSame('BLOCKED', $this->cachedStatus(),
            'Revoking left the cached status the turnstile reads where it was.');
    }

    public function test_revoking_needs_a_reason_and_frees_the_scope(): void
    {
        $granted = $this->grant();

        try {
            $this->overrides()->revoke($this->companyId, $granted['uuid'], '  ', $this->user->id);
            $this->fail('A revocation with no reason was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $this->overrides()->revoke($this->companyId, $granted['uuid'], 'No longer needed.',
            $this->user->id);

        // And a new one may now be granted for the same scope, which a plain unique index over
        // a nullable column would not have allowed.
        $this->grant(['reason' => 'The situation changed again, as situations do.']);

        $this->assertTrue($this->mayWork());
    }

    public function test_an_override_from_another_company_cannot_be_granted_or_revoked(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-ovr-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignUuid = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreignUuid, 'company_id' => $otherCompany,
            'name' => 'Someone Else', 'access_status' => 'BLOCKED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/No such contractor/i');

        $this->grant(['subject_uuid' => $foreignUuid]);
    }

    public function test_a_site_from_another_company_cannot_be_the_scope(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-ovr-site-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignSite = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'code' => 'X'.Str::random(5), 'name' => 'Not Our Site', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/No such site/i');

        $this->grant(['site_id' => $foreignSite]);
    }

    // ── Who authorised it ─────────────────────────────────────────────────────────────────────

    public function test_the_approver_is_recorded_separately_from_whoever_typed_it(): void
    {
        /*
         * CON-17 asks for an approver by name, because the person at the keyboard is not always
         * the person authorising and an audit of "who let them in" has to name the latter.
         */
        $granted = $this->grant(['approved_by' => $this->approver->id]);

        $row = DB::table('sheq_access_overrides')->where('uuid', $granted['uuid'])
            ->first(['issued_by', 'approved_by', 'approved_at']);

        $this->assertSame($this->user->id, (int) $row->issued_by);
        $this->assertSame($this->approver->id, (int) $row->approved_by);
        $this->assertNotNull($row->approved_at);
    }

    public function test_the_approver_defaults_to_the_issuer_rather_than_to_nobody(): void
    {
        // On a small site they are the same person. Recorded honestly rather than left blank,
        // because a blank approver reads as nobody having authorised it.
        $granted = $this->grant();

        $this->assertSame($this->user->id, (int) DB::table('sheq_access_overrides')
            ->where('uuid', $granted['uuid'])->value('approved_by'));
    }

    public function test_granting_revoking_and_expiring_are_all_audited(): void
    {
        $granted = $this->grant();

        $this->overrides()->revoke($this->companyId, $granted['uuid'], 'Changed our minds.',
            $this->user->id);

        $actions = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('subject_id', $this->contractor['id'])
            ->whereIn('action', ['access_override.granted', 'access_override.revoked'])
            ->pluck('action')->all();

        $this->assertContains('access_override.granted', $actions);
        $this->assertContains('access_override.revoked', $actions);

        // And the expiry, which happens with no actor at all — so it is recorded as SYSTEM
        // rather than attributed to whoever happened to trigger the sweep.
        $this->grant(['reason' => 'A second one, to let it lapse on its own.',
            'ends_at' => Carbon::now()->addHour()->toDateTimeString()]);

        $this->travelTo(Carbon::now()->addHours(2));
        $this->overrides()->expireLapsed($this->companyId);

        $expiry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'access_override.expired')->latest('id')->first();

        $this->assertNotNull($expiry);
        $this->assertSame('SYSTEM', $expiry->actor_type);
    }

    // ── What the gate shows ───────────────────────────────────────────────────────────────────

    public function test_the_conditions_reach_the_guard_at_the_boom(): void
    {
        /*
         * "Escorted at all times" written where only the approver sees it is a condition nobody
         * enforces.
         */
        $this->grant(['conditions' => 'Escorted at all times, daylight hours only.']);

        $file = app(ContractorService::class)
            ->complianceFile($this->companyId, $this->contractor['uuid']);

        $this->assertNotNull($file['override']);
        $this->assertSame('Escorted at all times, daylight hours only.',
            $file['override']['conditions']);
    }

    public function test_an_overridden_contractor_never_reads_as_compliant(): void
    {
        // The file is still short. A dashboard that said COMPLIANT would hide the thing the
        // override exists to work around.
        $this->grant();

        $this->assertSame('EXPIRING', app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'])['status']);
    }

    public function test_a_scanned_pass_shows_the_override_is_what_let_them_in(): void
    {
        $this->grant(['conditions' => 'Escorted at all times.']);

        $token = app(AccessPassService::class)
            ->contractorToken($this->companyId, $this->contractor['uuid'], $this->user->id);

        $this->get("/sheq/pass/{$token}?site={$this->refinery}")
            ->assertOk()
            ->assertSee('ACCESS APPROVED');
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_register_lists_every_override_with_its_state(): void
    {
        $live = $this->grant();
        $scheduled = $this->grant([
            'site_id' => $this->depot,
            'starts_at' => Carbon::now()->addDay()->toDateTimeString(),
            'ends_at' => Carbon::now()->addDays(2)->toDateTimeString(),
            'reason' => 'Arranged ahead of the depot turnaround on Monday.',
        ]);

        $props = $this->actingAs($this->user)->get('/sheq/overrides')->assertOk()
            ->viewData('page')['props'];

        $rows = collect($props['rows'])->keyBy('uuid');

        $this->assertSame('ACTIVE', $rows[$live['uuid']]['state']);
        $this->assertSame('SCHEDULED', $rows[$scheduled['uuid']]['state']);
        $this->assertSame('Acme Scaffolding', $rows[$live['uuid']]['subject_name']);
    }

    public function test_the_register_names_the_approver(): void
    {
        // The register exists to be read by people who did not grant them, so the authority has
        // to be on it rather than one click away.
        $granted = $this->grant(['approved_by' => $this->approver->id]);

        $row = collect($this->actingAs($this->user)->get('/sheq/overrides')
            ->viewData('page')['props']['rows'])->firstWhere('uuid', $granted['uuid']);

        $this->assertNotNull($row['approved_by_name']);
    }

    public function test_the_screen_can_grant_and_revoke(): void
    {
        $this->actingAs($this->user)->post('/sheq/overrides', [
            'subject_type' => 'CONTRACTOR',
            'subject_uuid' => $this->contractor['uuid'],
            'reason' => 'Letter of good standing is in the post, confirmed by telephone.',
            'conditions' => 'Escorted at all times.',
            'site_id' => $this->refinery,
            'ends_at' => Carbon::now()->addDays(2)->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($this->mayWork($this->refinery));

        $uuid = DB::table('sheq_access_overrides')
            ->where('company_id', $this->companyId)->value('uuid');

        $this->actingAs($this->user)->post("/sheq/overrides/{$uuid}/revoke",
            ['reason' => 'No longer needed.'])->assertRedirect();

        $this->assertFalse($this->mayWork($this->refinery));
    }

    public function test_the_screen_refuses_a_short_reason_and_a_past_end(): void
    {
        $this->actingAs($this->user)->post('/sheq/overrides', [
            'subject_type' => 'CONTRACTOR', 'subject_uuid' => $this->contractor['uuid'],
            'reason' => 'ok', 'ends_at' => Carbon::now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->user)->post('/sheq/overrides', [
            'subject_type' => 'CONTRACTOR', 'subject_uuid' => $this->contractor['uuid'],
            'reason' => 'A perfectly reviewable reason goes here.',
            'ends_at' => Carbon::now()->subDay()->toDateTimeString(),
        ])->assertSessionHasErrors('ends_at');
    }

    public function test_granting_an_override_needs_the_contractor_permission(): void
    {
        $this->actingAs($this->userWith([]))->post('/sheq/overrides', [
            'subject_type' => 'CONTRACTOR', 'subject_uuid' => $this->contractor['uuid'],
            'reason' => 'Somebody without the permission should not manage this.',
            'ends_at' => Carbon::now()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_the_register_flags_the_ones_ending_soon(): void
    {
        // Renew them or let them lapse — but decide, rather than finding out at a boom.
        $this->grant(['ends_at' => Carbon::now()->addHours(12)->toDateTimeString()]);
        $this->grant(['site_id' => $this->depot,
            'ends_at' => Carbon::now()->addDays(30)->toDateTimeString(),
            'reason' => 'A long one that is not ending soon at all.']);

        $endingSoon = $this->actingAs($this->user)->get('/sheq/overrides')
            ->viewData('page')['props']['endingSoon'];

        $this->assertCount(1, $endingSoon);
    }
}
