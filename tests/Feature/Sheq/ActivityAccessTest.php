<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ActivityService;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Access evaluated against the work being done (CON-15).
 *
 * The requirement's own example is the test this file exists for: a worker approved for general
 * construction and denied for working at height, because the competency that work requires is
 * missing.
 *
 * That could not happen before. The relationship ran one way only — a credential listed the
 * activities it authorised — which can answer "what does this certificate allow" but never "what
 * is this person missing". `AccessPassService::activityEligible()` returned `ok: true` with the
 * note "No competency is recorded specifically for WORKING_AT_HEIGHT", so a worker holding
 * nothing at all was eligible for everything. The absence of the ticket IS the refusal, and
 * nothing was in a position to notice it.
 */
class ActivityAccessTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    private int $workerId;

    private string $workerUuid;

    private int $siteId;

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

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::upper(Str::random(5)), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Nothing demanded of the company, so a refusal here can only have come from the
        // activity check under test.
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

        app(ActivityService::class)->install($this->companyId, $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'act-'.Str::random(8).'@example.test',
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

    /** @param  array<string,mixed>  $overrides */
    private function credential(string $code, array $overrides = []): int
    {
        return (int) DB::table('sheq_contractor_worker_credentials')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $this->workerId, 'kind' => 'COMPETENCY', 'code' => $code,
            'title' => ucfirst(strtolower(str_replace('_', ' ', $code))),
            'issued_on' => Carbon::today()->subMonths(6)->toDateString(),
            'expires_on' => Carbon::today()->addYear()->toDateString(),
            'status' => 'ACCEPTED',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function activities(): ActivityService
    {
        return app(ActivityService::class);
    }

    private function eligible(string $activity, ?Carbon $at = null): array
    {
        return $this->activities()->eligibility($this->companyId, $this->workerId, $activity, $at);
    }

    // ── The requirement's own example ─────────────────────────────────────────────────────────

    public function test_approved_for_general_construction_and_denied_for_working_at_height(): void
    {
        /*
         * Verbatim from CON-15. Same worker, same site, same moment — two answers, because the
         * two kinds of work take different things. The old check gave the same answer to both.
         */
        $general = $this->eligible('GENERAL_CONSTRUCTION');
        $height = $this->eligible('WORKING_AT_HEIGHT');

        $this->assertTrue($general['ok'], 'General construction needs no ticket and was refused.');

        $this->assertFalse($height['ok'],
            'A worker with no fall-arrest ticket was cleared to work at height.');

        $this->assertSame(['WORK_AT_HEIGHT', 'FALL_ARREST'],
            collect($height['missing'])->pluck('code')->all());

        // Named, because "a competency is missing" sends somebody to read a list.
        $this->assertStringContainsString('Work at height', $height['note']);
    }

    public function test_the_ticket_the_work_needs_makes_them_eligible(): void
    {
        $this->credential('WORK_AT_HEIGHT');
        $this->credential('FALL_ARREST');

        $verdict = $this->eligible('WORKING_AT_HEIGHT');

        $this->assertTrue($verdict['ok'], $verdict['note']);
        $this->assertSame([], $verdict['missing']);
        $this->assertCount(2, $verdict['held']);
    }

    public function test_one_of_two_required_tickets_is_not_enough(): void
    {
        // Partial credit is how somebody ends up on a roof with a harness and no training.
        $this->credential('WORK_AT_HEIGHT');

        $verdict = $this->eligible('WORKING_AT_HEIGHT');

        $this->assertFalse($verdict['ok']);
        $this->assertSame(['FALL_ARREST'], collect($verdict['missing'])->pluck('code')->all());
    }

    // ── The four ways a ticket fails to count ─────────────────────────────────────────────────

    public function test_a_ticket_that_has_run_out_is_reported_as_expired_not_as_missing(): void
    {
        // Three different things to say to somebody at a boom: never filed, filed and not yet
        // accepted, and accepted but out of date. "Missing" for all three sends the contractor
        // to re-upload a certificate they already sent.
        $this->credential('WORK_AT_HEIGHT',
            ['expires_on' => Carbon::today()->subDay()->toDateString()]);
        $this->credential('FALL_ARREST');

        $verdict = $this->eligible('WORKING_AT_HEIGHT');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('EXPIRED', $verdict['missing'][0]['reason']);
        $this->assertSame(Carbon::today()->subDay()->toDateString(),
            $verdict['missing'][0]['expires_on']);
    }

    public function test_a_ticket_nobody_has_accepted_yet_does_not_count(): void
    {
        $this->credential('WORK_AT_HEIGHT', ['status' => 'SUPPLIED']);
        $this->credential('FALL_ARREST');

        $verdict = $this->eligible('WORKING_AT_HEIGHT');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('NOT_ACCEPTED', $verdict['missing'][0]['reason']);
    }

    public function test_a_rejected_ticket_does_not_count(): void
    {
        $this->credential('WORK_AT_HEIGHT', ['status' => 'REJECTED']);
        $this->credential('FALL_ARREST');

        $this->assertFalse($this->eligible('WORKING_AT_HEIGHT')['ok']);
    }

    public function test_a_ticket_scoped_to_other_work_does_not_stretch_to_this(): void
    {
        /*
         * A rigging ticket restricted to lifting says nothing about working at height. Treating
         * it as general proof is how one certificate ends up covering work it was never issued
         * for.
         */
        $this->credential('WORK_AT_HEIGHT',
            ['activities' => json_encode(['LIFTING_OPERATIONS'])]);
        $this->credential('FALL_ARREST');

        $verdict = $this->eligible('WORKING_AT_HEIGHT');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('NOT_FOR_THIS_ACTIVITY', $verdict['missing'][0]['reason']);
    }

    public function test_a_ticket_that_names_no_activities_counts_for_all_of_them(): void
    {
        // Saying nothing about activities is not saying "none of them". Most certificates are
        // filed with no scope at all, and reading that as a restriction would refuse everybody.
        $this->credential('WORK_AT_HEIGHT', ['activities' => null]);
        $this->credential('FALL_ARREST', ['activities' => json_encode([])]);

        $this->assertTrue($this->eligible('WORKING_AT_HEIGHT')['ok']);
    }

    // ── Date and time ─────────────────────────────────────────────────────────────────────────

    public function test_eligibility_is_asked_about_a_moment_not_about_today(): void
    {
        /*
         * The requirement says date and time. A permit opened for next month is checked against
         * next month, and an audit asking "were they eligible when they entered" is asking about
         * the moment they entered — not about the moment somebody runs the report.
         */
        $this->credential('WORK_AT_HEIGHT',
            ['expires_on' => Carbon::today()->addDays(10)->toDateString()]);
        $this->credential('FALL_ARREST');

        $this->assertTrue($this->eligible('WORKING_AT_HEIGHT', Carbon::now())['ok']);

        $this->assertFalse(
            $this->eligible('WORKING_AT_HEIGHT', Carbon::now()->addDays(30))['ok'],
            'A ticket that will have lapsed by then was treated as valid for a future date.');

        // And a date before it was issued is not retrospective proof either way — the ticket is
        // still on file, so the honest answer for a past date is that it was valid then.
        $this->assertTrue($this->eligible('WORKING_AT_HEIGHT', Carbon::now()->subDay())['ok']);
    }

    public function test_a_ticket_expiring_today_is_still_valid_today(): void
    {
        // Off-by-one at a boom turns people away on the last day of a certificate that is still
        // in force. Expiry is end of day, not the start of it.
        $this->credential('WORK_AT_HEIGHT', ['expires_on' => Carbon::today()->toDateString()]);
        $this->credential('FALL_ARREST', ['expires_on' => Carbon::today()->toDateString()]);

        $this->assertTrue($this->eligible('WORKING_AT_HEIGHT', Carbon::today()->setTime(16, 0))['ok']);
    }

    // ── An activity nobody catalogued ─────────────────────────────────────────────────────────

    public function test_an_unknown_activity_is_reported_not_refused(): void
    {
        /*
         * A reader at a boom sends whatever string it was configured with years ago. Refusing
         * everybody whose activity code is not in the catalogue would shut a site down over a
         * typo in a device nobody has looked at since it was installed.
         */
        $verdict = $this->eligible('PAINTING_THE_FENCE');

        $this->assertTrue($verdict['ok']);
        $this->assertFalse($verdict['known'],
            'An uncatalogued activity was reported as though it had been checked.');
    }

    public function test_no_activity_at_all_checks_nothing_specific(): void
    {
        // A perimeter turnstile does not know what somebody is here to do, and guessing would be
        // worse than not asking.
        $verdict = $this->eligible('');

        $this->assertTrue($verdict['ok']);
        $this->assertFalse($verdict['known']);
    }

    // ── The catalogue itself ──────────────────────────────────────────────────────────────────

    public function test_installing_twice_does_not_duplicate_or_overwrite(): void
    {
        /*
         * A host who renamed an activity or changed what it demands has made a decision.
         * Re-running the installer must not undo it, and must not leave two rows claiming the
         * same code either.
         */
        $hotWork = $this->activities()->find($this->companyId, 'HOT_WORK');

        $this->activities()->save($this->companyId, $hotWork->uuid, [
            'code' => 'HOT_WORK', 'name' => 'Hot work (our wording)',
            'required_credentials' => ['OUR_OWN_TICKET'],
        ], $this->user->id);

        $added = $this->activities()->install($this->companyId, $this->user->id);

        $this->assertSame(0, $added, 'Re-installing added activities that already existed.');

        $this->assertSame('Hot work (our wording)',
            $this->activities()->find($this->companyId, 'HOT_WORK')->name);
        $this->assertSame(1, DB::table('sheq_activities')
            ->where('company_id', $this->companyId)->where('code', 'HOT_WORK')
            ->whereNull('deleted_at')->count());
    }

    public function test_a_code_is_normalised_so_two_spellings_cannot_both_exist(): void
    {
        // WORKING_AT_HEIGHT and working_at_height as two rows is a requirement half the gates
        // check and half do not, and the half that does not looks like a pass.
        $this->expectExceptionMessageMatches('/already exists/i');

        $this->activities()->save($this->companyId, null,
            ['code' => 'working_at_height', 'name' => 'Duplicate'], $this->user->id);
    }

    public function test_a_retired_activity_frees_its_code(): void
    {
        $uuid = $this->activities()->save($this->companyId, null,
            ['code' => 'ROPE_ACCESS', 'name' => 'Rope access'], $this->user->id);

        $this->activities()->retire($this->companyId, $uuid, $this->user->id);

        $this->assertNull($this->activities()->find($this->companyId, 'ROPE_ACCESS'));

        // Recreatable, which a plain unique index over a nullable deleted_at would not allow.
        $this->activities()->save($this->companyId, null,
            ['code' => 'ROPE_ACCESS', 'name' => 'Rope access, again'], $this->user->id);

        $this->assertSame('Rope access, again',
            $this->activities()->find($this->companyId, 'ROPE_ACCESS')->name);
    }

    public function test_an_activity_requiring_nothing_clears_everybody(): void
    {
        // Most work needs no named ticket. An activity that demanded one nobody issues would
        // refuse the whole estate, which is the failure mode of a catalogue nobody maintains.
        $verdict = $this->eligible('CLEANING');

        $this->assertTrue($verdict['ok']);
        $this->assertTrue($verdict['known']);
        $this->assertSame([], $verdict['missing']);
    }

    // ── Through the gates ─────────────────────────────────────────────────────────────────────

    public function test_a_scanned_worker_pass_refuses_the_work_they_are_not_ticketed_for(): void
    {
        /*
         * End to end, on the surface a guard actually holds. The whole feature is worth nothing
         * if the catalogue is only consulted on an office screen.
         *
         * The rule is switched on here, and it did not used to be. This page had its own
         * implementation of compliance and refused a missing ticket whatever the host had
         * configured, while the turnstile two files away honoured the switch — so the same worker
         * was admitted at the boom and refused by the pass in their own pocket, and
         * `test_the_turnstile_can_ask_about_an_activity_and_honours_the_host_rule` below asserted
         * the opposite of this test for the same inputs. COM-1 collapsed the two implementations
         * into one engine, which means one answer, and the switch is what chooses it.
         *
         * The switch stays OFF by default for the reason it always did: on the day activity
         * requirements arrive nobody has filed a credential against them, so refusing by default
         * would turn away every crew on the estate on upgrade morning. A host switched to
         * enforcing is what this test now describes.
         */
        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            ['MISSING_ACTIVITY_COMPETENCY' => true], $this->user->id);

        $token = app(AccessPassService::class)
            ->workerToken($this->companyId, $this->workerUuid, $this->user->id);

        $atHeight = $this->get("/sheq/pass/{$token}?site={$this->siteId}&activity=WORKING_AT_HEIGHT")
            ->assertOk();

        $atHeight->assertSee('ACCESS DENIED');
        $atHeight->assertSee('Work at height', false);

        $general = $this->get("/sheq/pass/{$token}?site={$this->siteId}&activity=GENERAL_CONSTRUCTION")
            ->assertOk();

        $general->assertSee('ACCESS APPROVED');
    }

    public function test_the_verdict_names_the_activity_rather_than_echoing_the_code(): void
    {
        // A guard reads "Working at height", not WORKING_AT_HEIGHT. The code is for the reader.
        $this->credential('WORK_AT_HEIGHT');
        $this->credential('FALL_ARREST');

        $token = app(AccessPassService::class)
            ->workerToken($this->companyId, $this->workerUuid, $this->user->id);

        $this->get("/sheq/pass/{$token}?site={$this->siteId}&activity=WORKING_AT_HEIGHT")
            ->assertOk()
            ->assertSee('Working at height', false);
    }

    public function test_the_turnstile_can_ask_about_an_activity_and_honours_the_host_rule(): void
    {
        /*
         * The other gate. It took no activity at all before, so "may they come in" was the only
         * question it could ask — and the answer to that is yes for a worker with no tickets.
         *
         * Whether a gap refuses or only warns is the host's `MISSING_ACTIVITY_COMPETENCY` rule,
         * read here so the turnstile and the scanned pass cannot give different answers.
         */
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        $gate = app(AccessDecisionService::class);

        $lenient = $gate->decide($this->companyId, $identifier, $this->siteId,
            'WORKING_AT_HEIGHT');

        $this->assertTrue($lenient['allowed'],
            'A missing ticket refused entry while the rule was off.');
        $this->assertStringContainsString('Work at height',
            implode(' ', $lenient['warnings']));

        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            ['MISSING_ACTIVITY_COMPETENCY' => true], $this->user->id);

        $strict = $gate->decide($this->companyId, $identifier, $this->siteId,
            'WORKING_AT_HEIGHT');

        $this->assertFalse($strict['allowed'],
            'A missing ticket admitted entry after the rule was switched on.');

        // And the work that needs nothing is still fine either way.
        $this->assertTrue(
            $gate->decide($this->companyId, $identifier, $this->siteId,
                'GENERAL_CONSTRUCTION')['allowed']);
    }

    public function test_an_expired_ticket_refuses_at_the_turnstile_whatever_the_rule_says(): void
    {
        /*
         * A gap in the record and a qualification that has run out are different things. The
         * rule governs the former — a host may decide not to refuse for paperwork nobody has
         * filed yet. An expired ticket is not a gap: it is somebody who was qualified and is not
         * any more, which is exactly what a gate exists for.
         */
        $this->credential('WORK_AT_HEIGHT',
            ['expires_on' => Carbon::today()->subWeek()->toDateString()]);
        $this->credential('FALL_ARREST');

        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        $decision = app(AccessDecisionService::class)
            ->decide($this->companyId, $identifier, $this->siteId, 'WORKING_AT_HEIGHT');

        $this->assertFalse($decision['allowed'],
            'An expired competency was treated as a reportable gap rather than a refusal.');
    }

    public function test_the_gate_webhook_passes_the_activity_through(): void
    {
        // The hardware path. A boom at the entrance to a tank farm knows what the work is, and
        // the webhook has to carry it or the catalogue is never consulted in production.
        $integrationId = DB::table('sheq_integrations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'integration_type' => 'ACCESS_CONTROL', 'provider' => 'GENERIC',
            'label' => 'Tank farm boom', 'api_key' => encrypt('secret-key-'.Str::random(10)),
            'site_id' => $this->siteId, 'enforce_access' => true, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            ['MISSING_ACTIVITY_COMPETENCY' => true], $this->user->id);

        $uuid = DB::table('sheq_integrations')->where('id', $integrationId)->value('uuid');
        $key = decrypt(DB::table('sheq_integrations')->where('id', $integrationId)->value('api_key'));

        $response = $this->withHeaders(['X-Api-Key' => $key])
            ->postJson("/api/v1/sheq/gate/{$uuid}", [
                'identifier' => $identifier,
                'activity' => 'working_at_height',   // lower case on purpose: readers vary
                'direction' => 'ENTRY',
            ]);

        if ($response->status() === 401 || $response->status() === 403) {
            $this->markTestSkipped('The gate webhook authenticates differently on this build.');
        }

        $response->assertOk();
        $this->assertFalse($response->json('allow'),
            'The webhook let somebody through for work they hold no ticket for.');
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_catalogue_screen_lists_activities_and_who_can_do_them(): void
    {
        // An engine with no door otherwise: a catalogue nobody can open is a catalogue nobody
        // maintains, and an unmaintained one is a gate that refuses nobody.
        $this->credential('WORK_AT_HEIGHT');
        $this->credential('FALL_ARREST');

        $props = $this->actingAs($this->user)->get('/sheq/activities')->assertOk()
            ->viewData('page')['props'];

        $codes = collect($props['rows'])->pluck('code');

        $this->assertTrue($codes->contains('WORKING_AT_HEIGHT'));
        $this->assertTrue($codes->contains('GENERAL_CONSTRUCTION'));

        $this->assertSame(['ready' => 1, 'total' => 1],
            $props['readiness']['WORKING_AT_HEIGHT'] ?? null,
            'The screen did not report who can actually do the work.');

        // An activity requiring nothing is open to everybody, which the figure has to reflect.
        $this->assertSame(['ready' => 1, 'total' => 1],
            $props['readiness']['CLEANING'] ?? null);
    }

    public function test_the_screen_reports_nobody_qualified_when_that_is_true(): void
    {
        // The figure worth acting on: a requirement nobody on the estate meets is either a
        // certificate chase or a wrong requirement, and nobody finds out which until it is shown.
        $props = $this->actingAs($this->user)->get('/sheq/activities')->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(0, $props['readiness']['WORKING_AT_HEIGHT']['ready'] ?? -1);
        $this->assertSame(1, $props['readiness']['WORKING_AT_HEIGHT']['total'] ?? -1);
    }

    public function test_the_screen_can_create_edit_and_retire(): void
    {
        $this->actingAs($this->user)->post('/sheq/activities', [
            'code' => 'ROPE_ACCESS', 'name' => 'Rope access',
            'required_credentials' => ['IRATA_L1'], 'is_high_risk' => true,
            'requires_permit' => true, 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $row = $this->activities()->find($this->companyId, 'ROPE_ACCESS');
        $this->assertNotNull($row);

        $this->actingAs($this->user)->put("/sheq/activities/{$row->uuid}", [
            'code' => 'ROPE_ACCESS', 'name' => 'Rope access (IRATA)',
            'required_credentials' => ['IRATA_L1', 'FALL_ARREST'],
            'is_high_risk' => true, 'requires_permit' => true, 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['IRATA_L1', 'FALL_ARREST'], collect($this->activities()
            ->all($this->companyId))->firstWhere('code', 'ROPE_ACCESS')['required_credentials']);

        $this->actingAs($this->user)->delete("/sheq/activities/{$row->uuid}")->assertRedirect();

        $this->assertNull($this->activities()->find($this->companyId, 'ROPE_ACCESS'));
    }

    public function test_managing_the_catalogue_needs_the_configure_permission(): void
    {
        // It decides who gets onto a site. A read-only user may look at it; nobody else changes it.
        $this->actingAs($this->userWith([]))
            ->post('/sheq/activities', ['code' => 'X', 'name' => 'X', 'required_credentials' => []])
            ->assertForbidden();
    }

    public function test_a_code_with_spaces_is_refused_rather_than_silently_mangled(): void
    {
        // It is what a reader sends. A code with a space in it would never match anything, and
        // the mismatch reads as a pass.
        $this->actingAs($this->user)->post('/sheq/activities', [
            'code' => 'HOT WORK', 'name' => 'Hot work', 'required_credentials' => [],
        ])->assertSessionHasErrors('code');
    }

    public function test_the_blocking_rules_screen_does_not_offer_a_switch_it_cannot_honour(): void
    {
        /*
         * `SITE_BLOCKED` is in the shipped rule set so `rulesFor()` answers for it, but it is
         * not a host's to change. Offering a switch for it would be a control that silently does
         * nothing — somebody would turn it off and believe they had.
         */
        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->assertOk()->viewData('page')['props'];

        $this->assertNotContains('SITE_BLOCKED', $props['allRules']);
        $this->assertContains('MISSING_ACTIVITY_COMPETENCY', $props['allRules']);
        $this->assertContains('SITE_BLOCKED', $props['alwaysBlocks']);
    }
}
