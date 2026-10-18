<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The four methods a corrected reachability audit found still unreached.
 *
 * Each had been reported as reached because a bare method-name grep counts a same-named *private*
 * method in an unrelated module as a caller — `expiringDocuments` exists in Procurement Governance
 * and Supplier Management too, `history` and `planFor` in three other services each.
 *
 * - `ContractorService::expiringDocuments()` — the Monday-morning failure: a letter of good
 *   standing expires over the weekend, the file recomputes, and a crew that has driven to site is
 *   turned away. Nobody was warned, because the expiry appeared only on the contractor's own
 *   profile, which nobody opens until there is a problem.
 * - `EmergencyService::planFor()` — resolves which plan governs, a site plan beating the
 *   company-wide one. The register listed every plan and left the reader to work out precedence:
 *   fine for an annual review, useless to somebody with a fire looking at two FIRE rows.
 * - `CompetencyService::matrix()` — answers "where are we short", which a flat list of records
 *   cannot.
 * - `ContractorPerformanceService::history()` — the contractor profile ran its own second query
 *   against the same table instead.
 */
class ReachedAtLastTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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
            'sheq.competency.manage']);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'reach-'.Str::random(8).'@example.test',
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

    // ── A contractor is warned before their paperwork lapses ──────────────────────────────────

    private function contractorWithDocument(string $expiresOn, ?string $email = 'site@acme.test'): void
    {
        $contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING', 'contact_email' => $email,
        ], $this->user->id);

        app(ContractorService::class)->attachDocument($this->companyId, $contractor['uuid'], [
            'document_type' => 'COIDA_LETTER', 'expires_on' => $expiresOn,
        ], $this->user->id);
    }

    private function reminders(): \Illuminate\Support\Collection
    {
        return DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.document_expiring')
            ->get();
    }

    public function test_a_contractor_is_chased_before_their_letter_lapses(): void
    {
        $this->contractorWithDocument(Carbon::today()->addDays(14)->toDateString());

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertExitCode(0);

        $reminder = $this->reminders()->first();

        $this->assertNotNull($reminder,
            'A crew turned away at the boom on Monday is the failure this prevents.');
        $this->assertSame('site@acme.test', $reminder->recipient_address);

        $payload = json_decode((string) $reminder->payload, true);
        $this->assertStringContainsString('14 days', (string) $payload['body']);
        $this->assertStringContainsString('refused entry', (string) $payload['body']);
    }

    public function test_a_document_lapsing_within_the_week_is_urgent(): void
    {
        $this->contractorWithDocument(Carbon::today()->addDays(3)->toDateString());

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertSame('HIGH', $this->reminders()->first()->severity);
    }

    public function test_a_document_lapsing_beyond_the_window_is_not_chased_yet(): void
    {
        // Chasing a certificate ten months out teaches a contractor to filter the sender.
        $this->contractorWithDocument(Carbon::today()->addMonths(10)->toDateString());

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertCount(0, $this->reminders());
    }

    public function test_a_contractor_with_no_contact_address_is_skipped(): void
    {
        $this->contractorWithDocument(Carbon::today()->addDays(10)->toDateString(), null);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        // Nothing to send to. The register still shows the expiry; inventing a recipient would
        // hide that nobody was reachable.
        $this->assertCount(0, $this->reminders());
    }

    public function test_running_twice_chases_once_but_a_renewal_chases_again(): void
    {
        $this->contractorWithDocument(Carbon::today()->addDays(14)->toDateString());

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);
        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertCount(1, $this->reminders());

        // A renewal moves the date, which is a new cycle and earns its own reminder.
        DB::table('sheq_contractor_documents')->where('company_id', $this->companyId)
            ->update(['expires_on' => Carbon::today()->addDays(20)->toDateString()]);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertCount(2, $this->reminders());
    }

    // ── Which emergency plan governs ──────────────────────────────────────────────────────────

    private function plan(string $type, string $name, ?int $siteId = null): void
    {
        DB::table('sheq_emergency_plans')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => $name, 'emergency_type' => $type, 'site_id' => $siteId,
            'procedure' => 'Evacuate and muster.',
            'assembly_points' => json_encode(['Car park A']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::random(5), 'name' => $name, 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function inForce(?int $siteId = null): array
    {
        $url = '/sheq/emergency'.($siteId === null ? '' : '?site_id='.$siteId);

        return collect($this->actingAs($this->user)->get($url)->assertOk()
            ->viewData('page')['props']['inForce'])->keyBy('emergency_type')->all();
    }

    public function test_the_site_plan_beats_the_company_wide_one(): void
    {
        $siteId = $this->site('Refinery');

        $this->plan('FIRE', 'Group fire plan');
        $this->plan('FIRE', 'Refinery fire plan', $siteId);

        // Two FIRE rows in the register and no statement of which governs. Somebody with a fire
        // cannot be asked to work out precedence.
        $this->assertSame('Refinery fire plan', $this->inForce($siteId)['FIRE']['plan']['name']);
    }

    public function test_the_company_wide_plan_is_the_fallback(): void
    {
        $siteId = $this->site('Depot');
        $this->plan('FIRE', 'Group fire plan');

        $this->assertSame('Group fire plan', $this->inForce($siteId)['FIRE']['plan']['name']);
    }

    public function test_an_emergency_type_with_no_plan_is_reported_as_a_gap(): void
    {
        $this->plan('FIRE', 'Group fire plan');

        $inForce = $this->inForce();

        // The emergency nobody wrote a procedure for is invisible in a list of the plans that do
        // exist, and it is the first thing worth knowing.
        $this->assertNotNull($inForce['FIRE']['plan']);
        $uncovered = collect($inForce)->filter(fn ($e) => $e['plan'] === null);
        $this->assertGreaterThan(0, $uncovered->count());
    }

    public function test_a_plan_past_its_review_date_is_still_in_force_and_says_so(): void
    {
        DB::table('sheq_emergency_plans')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Stale fire plan', 'emergency_type' => 'FIRE',
            'procedure' => 'Evacuate.', 'assembly_points' => json_encode(['Car park A']),
            'review_due' => Carbon::today()->subMonths(2)->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $entry = $this->inForce()['FIRE'];

        // Out of date is not the same as absent: it is still the procedure people will follow.
        $this->assertSame('Stale fire plan', $entry['plan']['name']);
        $this->assertTrue($entry['review_overdue']);
    }

    // ── Where we are short of competency ──────────────────────────────────────────────────────

    private function requireCompetencyOf(int $hrPersonId, string $code): int
    {
        $competencyId = (int) DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => $code, 'title' => 'First aid level 1', 'competency_type' => 'TRAINING',
            'validity_months' => 24, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $hrPersonId,
            'is_exempt' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $competencyId;
    }

    private function person(string $first): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'first_name' => $first, 'surname' => Str::random(6), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_matrix_shows_who_holds_what_and_where_the_gap_is(): void
    {
        $holder = $this->person('Holder');
        $short = $this->person('Short');

        $code = 'FA'.Str::random(5);
        $competencyId = $this->requireCompetencyOf($holder, $code);

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $short,
            'is_exempt' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_competency_records')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'hr_person_id' => $holder, 'competency_id' => $competencyId,
            'status' => 'COMPLIANT', 'achieved_at' => Carbon::today()->subMonth()->toDateString(),
            'expires_at' => Carbon::today()->addYear()->toDateString(),
            'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $props = $this->actingAs($this->user)->get('/sheq/competency/matrix')
            ->assertOk()->viewData('page')['props'];

        $rows = collect($props['matrix']['rows'])->keyBy('hr_person_id');

        $this->assertSame('COMPLIANT', $rows[$holder]['cells'][$code]['status']);
        $this->assertSame('NOT_STARTED', $rows[$short]['cells'][$code]['status']);

        // The column total is the finding a flat list of records cannot produce: one of two.
        $this->assertSame(2, $props['matrix']['by_competency'][$code]['required']);
        $this->assertSame(1, $props['matrix']['by_competency'][$code]['compliant']);
    }

    public function test_the_matrix_names_the_people_so_the_grid_is_readable(): void
    {
        $personId = $this->person('Readable');
        $this->requireCompetencyOf($personId, 'FA'.Str::random(5));

        $props = $this->actingAs($this->user)->get('/sheq/competency/matrix')
            ->viewData('page')['props'];

        // The service returns hr_person_ids; a grid of numbers is not a screen.
        $this->assertArrayHasKey($personId, $props['people']);
        $this->assertStringContainsString('Readable', $props['people'][$personId]);
    }

    public function test_nothing_required_of_anybody_is_not_reported_as_compliance(): void
    {
        $props = $this->actingAs($this->user)->get('/sheq/competency/matrix')
            ->viewData('page')['props'];

        // The screen says "nothing to check against" rather than showing 100%.
        $this->assertSame([], $props['matrix']['competencies']);
        $this->assertNull($props['matrix']['overall_percent']);
    }

    public function test_the_matrix_opens_for_somebody_who_cannot_manage_competencies(): void
    {
        // Reading where the organisation is short is not the same as changing it.
        $this->actingAs($this->userWith([]))->get('/sheq/competency/matrix')->assertOk();
    }

    // ── The contractor score history comes from the service ───────────────────────────────────

    public function test_the_profile_shows_the_score_history(): void
    {
        $contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);

        DB::table('sheq_contractor_scores')->insert([
            'company_id' => $this->companyId, 'contractor_id' => $contractor['id'],
            'period' => Carbon::today()->format('Y-m'), 'incidents' => 2,
            'high_potential_incidents' => 1, 'inspection_failures' => 0, 'access_refusals' => 3,
            'overdue_actions' => 1, 'workers_on_site' => 12, 'score' => 64.5, 'rating' => 'FAIR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $scores = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props']['scores'];

        // One reader of sheq_contractor_scores, not two: the screen and the report have to agree.
        $this->assertCount(1, $scores);
        $this->assertSame('FAIR', $scores[0]['rating']);
        $this->assertSame(2, (int) $scores[0]['incidents']);
    }
}
