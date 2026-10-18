<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Http\Middleware\SetLocale;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The QR pass and what a scan resolves to (CON-11, CON-12, CON-13).
 *
 * Three properties the whole design rests on, and most of these tests are about them:
 *
 * **The code carries nothing.** A QR is printed on a card, photographed and forwarded on WhatsApp,
 * so whatever is encoded in it is published. An ID number inside a QR is an ID number on a
 * photocopier.
 *
 * **A static code lies.** Encode "COID valid until March" and it keeps saying so in April. The
 * token resolves to a live check, so the same printed card reads APPROVED today and DENIED
 * tomorrow when the insurance lapses.
 *
 * **It answers without a login.** A guard at a boom holding their own phone has no account, and a
 * check that needs one is a check that gets replaced by waving people through.
 */
class AccessPassTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

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
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure',
            'sheq.risk_assessment.author']);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode(['COIDA_LETTER']),
                'updated_at' => now()],
        );

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'P'.Str::random(5), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'pass-'.Str::random(8).'@example.test',
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

    private function passes(): AccessPassService
    {
        return app(AccessPassService::class);
    }

    private function compliant(): void
    {
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);
    }

    private function worker(string $name = 'T. Mokoena', array $overrides = []): object
    {
        $id = DB::table('sheq_contractor_workers')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => $name, 'trade' => 'SCAFFOLDING',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return DB::table('sheq_contractor_workers')->where('id', $id)->first();
    }

    // ── CON-11: the code carries nothing but a token ──────────────────────────────────────────

    public function test_the_token_is_opaque_and_long(): void
    {
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $token);

        /*
         * Not derivable from anything visible. A token anybody could compute from the uuid on the
         * URL bar would not be a credential, and this endpoint answers without a login.
         *
         * Checking the token does not *contain* the record id is not that test: an id of 1 is a
         * single character, and a random 64-character token holds a given digit about two times
         * in three, so that assertion failed the suite on its own. What has to be ruled out is a
         * token anybody could compute, so rule out the computations.
         */
        $uuid = $this->contractor['uuid'];

        $this->assertStringNotContainsString($uuid, $token);
        $this->assertNotSame($uuid, $token);

        foreach ([
            'raw uuid, dashes stripped' => str_replace('-', '', $uuid),
            'md5 of the uuid' => md5($uuid),
            'sha1 of the uuid' => sha1($uuid),
            'sha256 of the uuid' => hash('sha256', $uuid),
            'base64 of the uuid' => base64_encode($uuid),
            'md5 of the id' => md5((string) $this->contractor['id']),
            'sha256 of the id' => hash('sha256', (string) $this->contractor['id']),
        ] as $how => $guess) {
            $this->assertStringNotContainsString($guess, $token, "The token is the {$how}.");
        }

        // And not a constant: a second contractor gets its own token.
        $other = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Bravo Rigging', 'trade' => 'RIGGING',
        ], $this->user->id);

        $this->assertNotSame(
            $token,
            $this->passes()->contractorToken($this->companyId, $other['uuid']),
        );
    }

    public function test_the_qr_image_encodes_only_the_verification_url(): void
    {
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        DB::table('sheq_contractors')->where('uuid', $this->contractor['uuid'])
            ->update(['registration_number' => '2009/123456/07']);

        $svg = $this->actingAs($this->user)
            ->get("/sheq/pass/contractors/{$this->contractor['uuid']}/qr.svg")
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')
            ->getContent();

        /*
         * CON-11 is explicit: never an ID number, medical information, a certificate, personal
         * information or the safety file inside the code. An SVG is text, so anything encoded
         * would be readable right here — and on the card, and in the photograph of the card.
         */
        $this->assertStringNotContainsString('2009/123456/07', $svg);
        $this->assertStringNotContainsString('Acme Scaffolding', $svg);
        $this->assertStringNotContainsString('COIDA', $svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_the_image_is_never_cached(): void
    {
        // A rotated card must stop working everywhere at once; a cached image of the retired code
        // would keep being handed out by a proxy.
        $header = $this->actingAs($this->user)
            ->get("/sheq/pass/contractors/{$this->contractor['uuid']}/qr.svg")
            ->assertOk()->headers->get('Cache-Control');

        // The framework normalises the header, so the directive is asserted rather than the exact
        // string — what matters is that no proxy keeps a copy.
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringContainsString('private', $header);
    }

    public function test_the_same_token_is_returned_rather_than_a_new_one_each_time(): void
    {
        $first = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);
        $second = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        // A card is printed once. Re-issuing on every read would invalidate it by looking at it.
        $this->assertSame($first, $second);
    }

    public function test_printing_a_pass_needs_the_contractor_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->get("/sheq/pass/contractors/{$this->contractor['uuid']}/qr.svg")
            ->assertForbidden();
    }

    // ── CON-12: the scan is live, and needs no login ──────────────────────────────────────────

    public function test_a_scan_answers_without_an_account(): void
    {
        $this->compliant();
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        // No actingAs: this is a guard's own phone.
        $this->get("/sheq/pass/{$token}")
            ->assertOk()
            ->assertSee('ACCESS APPROVED')
            ->assertSee('Acme Scaffolding');
    }

    public function test_the_same_code_changes_from_approved_to_denied_by_itself(): void
    {
        $this->compliant();
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $this->get("/sheq/pass/{$token}")->assertSee('ACCESS APPROVED');

        // The certificate lapses where it sits. Nobody reprints the card; nobody rescans anything
        // but the same code.
        DB::table('sheq_contractor_documents')->where('company_id', $this->companyId)
            ->update(['expires_on' => Carbon::today()->subDay()->toDateString()]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        /*
         * The property that makes this worth building. A static PDF or an encoded expiry date
         * would still be saying APPROVED.
         */
        $this->get("/sheq/pass/{$token}")->assertSee('ACCESS DENIED');
    }

    public function test_a_denial_names_the_exact_reason(): void
    {
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        // "Access denied" at a boom with a queue behind it starts an argument.
        $this->get("/sheq/pass/{$token}")
            ->assertSee('ACCESS DENIED')
            ->assertSee('Refused because')
            ->assertSee('not been supplied', false);
    }

    public function test_the_checks_are_listed_by_name(): void
    {
        $this->compliant();
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $this->get("/sheq/pass/{$token}?site={$this->siteId}")
            ->assertSee('Safety file')
            ->assertSee('COID')
            ->assertSee('Insurance')
            ->assertSee('Site approval');
    }

    public function test_an_unknown_code_is_unknown_rather_than_denied(): void
    {
        /*
         * Different facts, and a guard has to tell them apart: a denial means this person may not
         * come in, an unknown code means the card is not ours or has been replaced. Answering
         * "denied" to a rotated card sends somebody to argue about compliance instead of being
         * issued a new card.
         */
        $this->get('/sheq/pass/'.Str::random(64))
            ->assertOk()
            ->assertSee('UNKNOWN PASS')
            ->assertDontSee('ACCESS DENIED');
    }

    public function test_a_rotated_card_stops_resolving(): void
    {
        $this->compliant();
        $old = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $new = $this->passes()->rotate($this->companyId, 'CONTRACTOR',
            $this->contractor['uuid'], $this->user->id);

        $this->assertNotSame($old, $new);

        // A card is lost and a dismissed worker keeps theirs.
        $this->get("/sheq/pass/{$old}")->assertSee('UNKNOWN PASS');
        $this->get("/sheq/pass/{$new}")->assertSee('ACCESS APPROVED');
    }

    public function test_rotation_is_audited_so_a_dead_card_has_an_explanation(): void
    {
        $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);
        $this->passes()->rotate($this->companyId, 'CONTRACTOR', $this->contractor['uuid'],
            $this->user->id);

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)->where('action', 'access_pass.rotated')
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry, '"My card stopped working" needs an answer.');
        $this->assertSame($this->user->id, (int) $entry->actor_id);
    }

    public function test_a_short_token_is_refused_by_the_route(): void
    {
        /*
         * The route constraint refuses it before anything touches the database, so a scanner
         * sending rubbish cannot be used to probe for tokens. It does not reach the page: an
         * unmatched URL is handled by the application's own fallback, which for a guest is a
         * redirect rather than a 404.
         */
        $response = $this->get('/sheq/pass/abc');

        $this->assertNotSame(200, $response->getStatusCode());
        $response->assertDontSee('UNKNOWN PASS')->assertDontSee('ACCESS');
    }

    public function test_every_scan_is_recorded_in_the_access_register(): void
    {
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $this->get("/sheq/pass/{$token}?site={$this->siteId}&device=GATE-1");

        $event = DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->where('direction', 'VERIFY')
            ->orderByDesc('id')->first();

        /*
         * The register the access gate already writes to, not a second log: "who was on site when
         * it happened" has to be answerable from one place. And the refusals are what tell a
         * safety manager the backlog is real.
         */
        $this->assertNotNull($event);
        $this->assertFalse((bool) $event->allowed);
        $this->assertSame('GATE-1', $event->device_ref);
        $this->assertSame($this->siteId, (int) $event->site_id);
    }

    public function test_the_access_log_never_holds_the_token(): void
    {
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $this->get("/sheq/pass/{$token}");

        $event = DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->orderByDesc('id')->first();

        // A log holding the credential would turn a database read into a working card.
        $this->assertNull($event->identifier);
        $this->assertStringNotContainsString($token, json_encode($event));
    }

    public function test_a_suspended_file_denies_the_contractor_pass(): void
    {
        $this->compliant();

        app(\App\Modules\Operations\Sheq\Services\SafetyFileService::class)
            ->applyProfile($this->companyId, 'GENERAL', $this->user->id);
        DB::table('sheq_safety_file_sections')->where('company_id', $this->companyId)
            ->update(['is_required' => false]);

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->suspend($this->companyId, $this->contractor['uuid'],
            'Unreported incident last week.', $this->user->id);

        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        $this->get("/sheq/pass/{$token}")
            ->assertSee('ACCESS DENIED')
            ->assertSee('Unreported incident');
    }

    // ── CON-13: the worker pass ───────────────────────────────────────────────────────────────

    public function test_a_worker_pass_checks_the_worker_and_their_employer(): void
    {
        $this->compliant();
        $worker = $this->worker();

        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        $this->get("/sheq/pass/{$token}")
            ->assertOk()
            ->assertSee('ACCESS APPROVED')
            ->assertSee('T. Mokoena')
            ->assertSee('Acme Scaffolding')
            ->assertSee('Identity')
            ->assertSee('Induction')
            ->assertSee('Competency')
            ->assertSee('Medical');
    }

    public function test_a_compliant_worker_is_refused_when_their_employer_is_blocked(): void
    {
        // No COID letter: the contractor is blocked.
        $worker = $this->worker();
        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        /*
         * The refusal is about the company they arrive with. A worker pass that ignored the
         * contractor's standing would be a way around the contractor gate entirely.
         */
        $this->get("/sheq/pass/{$token}")->assertSee('ACCESS DENIED');
    }

    public function test_a_worker_off_the_roster_is_refused_by_name(): void
    {
        $this->compliant();
        $worker = $this->worker('N. Dube', ['is_active' => false]);

        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        // A dismissed worker keeps their card. The roster is what stops it working.
        $this->get("/sheq/pass/{$token}")
            ->assertSee('ACCESS DENIED')
            ->assertSee('no longer on', false);
    }

    public function test_a_worker_without_a_current_induction_is_refused(): void
    {
        $this->compliant();
        $worker = $this->worker('N. Dube', ['inducted_on' => null, 'induction_expires' => null]);

        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        $this->get("/sheq/pass/{$token}")
            ->assertSee('ACCESS DENIED')
            ->assertSee('site induction', false);
    }

    public function test_an_expired_medical_refuses_the_worker_and_names_the_date(): void
    {
        $this->compliant();
        $worker = $this->worker();

        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $worker->id, 'kind' => 'MEDICAL',
            'title' => 'Certificate of fitness', 'status' => 'ACCEPTED',
            'expires_on' => Carbon::today()->subDays(9)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        $this->get("/sheq/pass/{$token}")
            ->assertSee('ACCESS DENIED')
            ->assertSee('Certificate of fitness')
            ->assertSee(Carbon::today()->subDays(9)->toDateString());
    }

    public function test_a_worker_with_nothing_on_record_is_not_refused_for_that_alone(): void
    {
        $this->compliant();
        $worker = $this->worker();

        /*
         * A contractor who has filed no credentials has not failed a check; the host has not asked
         * for them. CON-10's rules decide whether that refuses entry, and asserting a refusal here
         * as well would refuse everybody on the day the feature ships.
         */
        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        $this->get("/sheq/pass/{$token}")
            ->assertSee('ACCESS APPROVED')
            ->assertSee('Nothing on record');
    }

    public function test_an_expired_ticket_for_other_work_does_not_refuse_this_work(): void
    {
        $this->compliant();
        $worker = $this->worker();

        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $worker->id, 'kind' => 'COMPETENCY', 'title' => 'Rigging',
            'status' => 'ACCEPTED', 'activities' => json_encode(['LIFTING']),
            'expires_on' => Carbon::today()->subMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        // They may paint but not lift, and the scanner at the boom knows which work it guards.
        $this->get("/sheq/pass/{$token}?activity=LIFTING")->assertSee('ACCESS DENIED');
        $this->get("/sheq/pass/{$token}?activity=PAINTING")->assertSee('ACCESS APPROVED');
    }

    public function test_a_worker_whose_employer_was_removed_is_refused_not_crashed(): void
    {
        $this->compliant();
        $worker = $this->worker();
        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        $this->get("/sheq/pass/{$token}")->assertSee('ACCESS APPROVED');

        // The contractor is taken off the register. Their workers' rows stay behind.
        DB::table('sheq_contractors')->where('uuid', $this->contractor['uuid'])
            ->update(['deleted_at' => now()]);

        /*
         * This page is public and a guard sees whatever it returns. Reaching `mayWork()` with a
         * deleted contractor throws, which would be a 500 on a phone at a boom — and a broken
         * screen at a gate ends with people being waved through. A refusal naming the reason is
         * both the right answer and the safe one.
         */
        $this->get("/sheq/pass/{$token}")
            ->assertOk()
            ->assertSee('ACCESS DENIED')
            ->assertSee('no longer registered', false);
    }

    public function test_a_worker_whose_employer_is_deactivated_is_refused(): void
    {
        $this->compliant();
        $worker = $this->worker();
        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        DB::table('sheq_contractors')->where('uuid', $this->contractor['uuid'])
            ->update(['is_active' => false]);

        $this->get("/sheq/pass/{$token}")->assertOk()->assertSee('ACCESS DENIED');
    }

    public function test_the_pass_reports_the_ppe_the_risk_assessment_requires(): void
    {
        $this->compliant();
        $worker = $this->worker();

        $assessments = app(RiskAssessmentService::class);

        $assessment = $assessments->create($this->companyId, [
            'title' => 'Scaffolding', 'site_id' => $this->siteId,
        ], $this->user->id);

        $assessments->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'ERECTING', 'hazard' => 'Fall from height',
            'inherent_likelihood' => 4, 'inherent_severity' => 5,
            'residual_likelihood' => 2, 'residual_severity' => 3,
        ], [
            ['hierarchy_level' => 'ENGINEERING', 'description' => 'Edge protection'],
            ['hierarchy_level' => 'PPE', 'description' => 'Full-body harness and lanyard'],
        ]);

        $assessments->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
        $assessments->approve($this->companyId, $assessment['uuid'], $this->user->id,
            ['SITE_MANAGER']);

        $token = $this->passes()->workerToken($this->companyId, $worker->uuid);

        /*
         * The PPE-level controls on the assessment in force are the authoritative statement of
         * what the work needs — written by whoever assessed it. Reading a separate PPE list would
         * be a second answer to the same question.
         */
        $this->get("/sheq/pass/{$token}?site={$this->siteId}&activity=ERECTING")
            ->assertSee('PPE required for this work')
            ->assertSee('Full-body harness and lanyard')
            // Reported, not asserted: no database knows whether this person is wearing it.
            ->assertSee('Check it on the person');
    }

    public function test_the_page_is_not_indexed(): void
    {
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        // A verdict that is only true at the moment it was asked must not be in a search index.
        $this->get("/sheq/pass/{$token}")->assertSee('noindex', false);
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_a_scan_resolves_inside_the_tokens_own_tenant(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'pass-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignUuid = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreignUuid, 'company_id' => $other, 'name' => 'Theirs',
            'access_status' => ContractorComplianceStatus::BLOCKED, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = $this->passes()->contractorToken($other, $foreignUuid);

        /*
         * The request has no session, so no tenant is resolved. The token is looked up across
         * tenants once and everything after it runs stamped — which is what keeps row-level
         * security doing its job on an unauthenticated path.
         */
        $this->get("/sheq/pass/{$token}")->assertOk()->assertSee('Theirs');

        $this->assertSame(1, DB::table('sheq_access_events')
            ->where('company_id', $other)->where('direction', 'VERIFY')->count());
        $this->assertSame(0, DB::table('sheq_access_events')
            ->where('company_id', $this->companyId)->where('direction', 'VERIFY')->count());
    }

    public function test_another_tenants_pass_cannot_be_printed_from_here(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'pass-x-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignUuid = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreignUuid, 'company_id' => $other, 'name' => 'Theirs',
            'access_status' => 'BLOCKED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * Minting a card for another company's contractor would be minting a working credential.
         * The refusal arrives as a redirect carrying the message, not a 500: SHEQ refusals are
         * rendered to the person who hit them.
         */
        $this->actingAs($this->user)
            ->get("/sheq/pass/contractors/{$foreignUuid}/qr.svg")
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNull(DB::table('sheq_contractors')->where('uuid', $foreignUuid)
            ->value('qr_token'));
    }

    // ── Every check the service can emit has a label ──────────────────────────────────────────

    public function test_every_check_key_has_a_label(): void
    {
        $this->compliant();
        $worker = $this->worker();

        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $worker->id, 'kind' => 'COMPETENCY', 'title' => 'Scaffold erector',
            'status' => 'ACCEPTED', 'activities' => json_encode(['ERECTING']),
            'expires_on' => Carbon::today()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            $this->passes()->verify(
                $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']),
                $this->siteId, 'ERECTING'),
            $this->passes()->verify(
                $this->passes()->workerToken($this->companyId, $worker->uuid),
                $this->siteId, 'ERECTING'),
        ] as $result) {
            foreach ($result['checks'] as $check) {
                /*
                 * A check added without a label renders as a raw key on the one screen a guard
                 * reads at a boom. Asserted against what the service actually emits, so adding a
                 * check and forgetting the label fails here.
                 */
                $this->assertNotSame('sheq-pass.'.$check['key'], __('sheq-pass.'.$check['key']),
                    "The check {$check['key']} has no label.");
            }
        }
    }

    public function test_the_page_speaks_the_language_of_the_phone_that_scanned_it(): void
    {
        $this->compliant();
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        /*
         * This page is the one place in the product where the reader has no account, so there is
         * no saved preference to go on — the guard's own handset decides. It loads with no
         * session, so nothing else would make it match the rest of the screen they are holding.
         *
         * The language is whichever one ships besides English, read off disk rather than named
         * here: the build now ships English alone, and a test that named isiZulu asserted a
         * string that no longer exists instead of the behaviour it was written for. Asserted
         * against that locale's own words, so it keeps testing the handset-decides path for
         * whatever languages come back.
         */
        $locales = array_values(array_diff(SetLocale::supported(), ['en']));
        $locale = $locales[0] ?? 'en';

        $expected = (require base_path("lang/{$locale}/sheq-pass.php"))['page']['approved'];

        $response = $this->withHeader('Accept-Language', "{$locale}-ZA,{$locale};q=0.9")
            ->get("/sheq/pass/{$token}")
            ->assertOk()
            ->assertSee($expected)
            // The page declares the language it is in, so a screen reader pronounces it correctly.
            ->assertSee('<html lang="'.$locale.'">', false);

        if ($locale !== 'en') {
            // Only meaningful where the two differ: on an English-only build the expected verdict
            // IS the English one, and asserting its absence would contradict the line above.
            $response->assertDontSee('ACCESS APPROVED');
        }
    }

    public function test_the_two_verdicts_cannot_be_mistaken_for_each_other_in_any_language(): void
    {
        /*
         * The page's own design note says colour is never the only signal, because a guard may not
         * tell red from green and a photocopy has no colour at all — so the words have to carry
         * it. Three languages defeated that: "AKUVUMELEKILE UKUNGENA" (refused) contained
         * "KUVUMELEKILE UKUNGENA" (approved), a one-letter difference at the front, read at a
         * glance on a phone with a queue behind it. This also made an earlier version of the
         * isiZulu test pass on a refusal.
         */
        foreach (SetLocale::supported() as $locale) {
            $page = (require base_path("lang/{$locale}/sheq-pass.php"))['page'];

            $this->assertStringNotContainsString($page['approved'], $page['denied'],
                "In '{$locale}' the refusal contains the approval word for word, so a guard "
                .'glancing at the screen can read one as the other.');
            $this->assertStringNotContainsString($page['denied'], $page['approved'],
                "In '{$locale}' the approval contains the refusal word for word.");

            // And they must not merely differ by a leading letter either.
            $this->assertNotSame(
                strtolower(substr($page['approved'], 1)), strtolower(substr($page['denied'], 1)),
                "In '{$locale}' the two verdicts differ only by their first character.");
        }
    }

    public function test_a_phone_set_to_a_language_we_do_not_ship_still_gets_a_readable_verdict(): void
    {
        $this->compliant();
        $token = $this->passes()->contractorToken($this->companyId, $this->contractor['uuid']);

        // Falling back is the point: a guard with a German handset must still be told whether to
        // lift the boom, and silence or a raw translation key is not an answer.
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->get("/sheq/pass/{$token}")
            ->assertOk()
            ->assertSee('ACCESS APPROVED');
    }

    public function test_an_unknown_code_is_reported_as_unknown_in_the_scanner_language(): void
    {
        // Same reason as the verdict test above: the locale and both words come off disk, so an
        // English-only build tests the unknown-card path in English instead of failing on an
        // Afrikaans string that no longer ships.
        $locales = array_values(array_diff(SetLocale::supported(), ['en']));
        $locale = $locales[0] ?? 'en';

        $page = (require base_path("lang/{$locale}/sheq-pass.php"))['page'];

        $this->withHeader('Accept-Language', "{$locale}-ZA,{$locale};q=0.9")
            ->get('/sheq/pass/'.Str::random(64))
            ->assertOk()
            ->assertSee($page['unknown_heading'])
            // An unknown code is not a refusal: a guard must not turn somebody away over a QR
            // that simply is not ours to read.
            ->assertDontSee($page['denied']);
    }
}
