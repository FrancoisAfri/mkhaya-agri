<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Access\Integrations\AccessIntegrationManager;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\BulkImportService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gate decisions, site induction, and bulk onboarding.
 *
 * The gate is where compliance stops being a report. These tests are about the two failure modes
 * that matter: letting somebody through who should be stopped, and — just as damaging in practice —
 * stranding a shift outside because the data was not ready.
 */
class AccessAndInductionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

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

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'G'.Str::random(6), 'name' => 'Gate site', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(string $idNumber): int
    {
        return DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Gate',
            'surname' => 'Person'.Str::random(4), 'id_number' => $idNumber,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function induction(array $overrides = []): array
    {
        return app(InductionService::class)->publish($this->companyId, array_merge([
            'code' => 'IND'.Str::random(5), 'title' => 'Site induction',
            'audience' => 'EMPLOYEE',
            'content' => [['heading' => 'Assembly point', 'body' => 'Car park A.']],
            'questions' => [['id' => 'q1', 'text' => 'Where is the assembly point?',
                'options' => ['Car park A', 'The canteen'], 'answer' => 'Car park A']],
            'requires_signature' => false,
        ], $overrides));
    }

    // ── The gate ──────────────────────────────────────────────────────────────────────────────

    public function test_an_unknown_identifier_is_told_what_to_do_next(): void
    {
        $decision = app(AccessDecisionService::class)
            ->decide($this->companyId, 'not-a-real-card', $this->siteId);

        $this->assertFalse($decision['allowed']);
        // "Unknown card" starts an argument at the boom. This tells them where to go.
        $this->assertStringContainsString('reception', strtolower($decision['reasons'][0]));
    }

    public function test_a_medically_unfit_employee_is_refused_with_the_reason(): void
    {
        $idNumber = '85010158000'.random_int(10, 99);
        $personId = $this->person($idNumber);

        $typeId = DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'M'.Str::random(5), 'name' => 'Audiometry',
            'examination_type' => 'AUDIOMETRY', 'frequency_months' => 12,
            'blocks_work_when_unfit' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $typeId, 'hr_person_id' => $personId,
            'fitness_outcome' => 'UNFIT',
        ]);

        $decision = app(AccessDecisionService::class)
            ->decide($this->companyId, $idNumber, $this->siteId);

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString('Medical', $decision['reasons'][0]);
    }

    public function test_an_expired_induction_names_the_date_it_expired(): void
    {
        $idNumber = '85010158001'.random_int(10, 99);
        $personId = $this->person($idNumber);
        $induction = $this->induction();

        DB::table('sheq_induction_completions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'induction_id' => $induction['id'], 'subject_type' => 'EMPLOYEE',
            'subject_id' => $personId, 'person_name' => 'Gate Person',
            'induction_version' => 1, 'passed' => true,
            'completed_at' => now()->subYears(2),
            'expires_on' => Carbon::today()->subMonths(3)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $decision = app(AccessDecisionService::class)
            ->decide($this->companyId, $idNumber, $this->siteId);

        $this->assertFalse($decision['allowed']);
        // The guard reads this aloud. A date is actionable; "denied" is not.
        $this->assertMatchesRegularExpression('/induction expired on \d/i', $decision['reasons'][0]);
    }

    public function test_a_company_with_no_induction_published_does_not_lock_everyone_out(): void
    {
        $idNumber = '85010158002'.random_int(10, 99);
        $this->person($idNumber);

        // Nothing published for this audience. Refusing here would mean adopting SHEQ locks a
        // workforce out of its own site on day one.
        $decision = app(AccessDecisionService::class)
            ->decide($this->companyId, $idNumber, $this->siteId);

        $this->assertTrue($decision['allowed']);
    }

    public function test_monitor_mode_records_the_refusal_but_still_opens_the_gate(): void
    {
        $decision = app(AccessDecisionService::class)->decideAndRecord(
            $this->companyId, 'unknown-person', ['site_id' => $this->siteId, 'enforce' => false]);

        $this->assertFalse($decision['allowed']);
        // The whole point of monitor mode: see who would be refused without stranding a shift.
        $this->assertTrue($decision['open_gate']);

        $event = DB::table('sheq_access_events')->where('company_id', $this->companyId)
            ->orderByDesc('id')->first(['allowed', 'was_monitor_only']);

        $this->assertFalse((bool) $event->allowed);
        $this->assertTrue((bool) $event->was_monitor_only);
    }

    public function test_enforcement_closes_the_gate(): void
    {
        $decision = app(AccessDecisionService::class)->decideAndRecord(
            $this->companyId, 'unknown-person', ['site_id' => $this->siteId, 'enforce' => true]);

        $this->assertFalse($decision['open_gate']);
    }

    public function test_the_impact_report_shows_what_enforcement_would_do(): void
    {
        $service = app(AccessDecisionService::class);

        foreach (['a', 'b', 'c'] as $who) {
            $service->decideAndRecord($this->companyId, "unknown-{$who}",
                ['site_id' => $this->siteId, 'enforce' => false]);
        }

        $impact = $service->enforcementImpact($this->companyId, $this->siteId);

        // The report a site manager reads before flipping the switch.
        $this->assertSame(3, $impact['would_refuse']);
        $this->assertNotEmpty($impact['by_reason']);
    }

    // ── Induction ─────────────────────────────────────────────────────────────────────────────

    public function test_answers_are_never_sent_to_the_device(): void
    {
        $this->induction();

        $presented = app(InductionService::class)->present($this->companyId, 'EMPLOYEE');

        // Trusting a client not to read the correct answer is an honesty system with a network
        // trace, not a quiz.
        $this->assertArrayNotHasKey('answer', $presented['questions'][0]);
        $this->assertArrayHasKey('options', $presented['questions'][0]);
    }

    public function test_a_wrong_answer_fails_and_says_which_question(): void
    {
        $induction = $this->induction();

        $result = app(InductionService::class)->complete($this->companyId, $induction['uuid'], [
            'subject_type' => 'EMPLOYEE', 'person_name' => 'Test Person',
            'answers' => ['q1' => 'The canteen'],
        ]);

        $this->assertFalse($result['passed']);
        // An induction teaches; "you scored 0%" teaches nothing.
        $this->assertContains('Where is the assembly point?', $result['failed_questions']);
        $this->assertNull($result['expires_on']);
    }

    public function test_a_pass_carries_an_expiry(): void
    {
        $induction = $this->induction(['validity_months' => 12]);

        $result = app(InductionService::class)->complete($this->companyId, $induction['uuid'], [
            'subject_type' => 'EMPLOYEE', 'person_name' => 'Test Person',
            'answers' => ['q1' => 'Car park A'],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame(Carbon::today()->addMonths(12)->toDateString(), $result['expires_on']);
    }

    public function test_an_nda_induction_refuses_completion_without_acceptance(): void
    {
        $induction = $this->induction(['requires_nda' => true, 'nda_text' => 'Site rules apply.']);

        $this->expectExceptionMessageMatches('/must be accepted/');

        app(InductionService::class)->complete($this->companyId, $induction['uuid'], [
            'subject_type' => 'EMPLOYEE', 'person_name' => 'Test Person',
            'answers' => ['q1' => 'Car park A'],
        ]);
    }

    public function test_republishing_makes_a_new_version_and_keeps_the_old_completions_truthful(): void
    {
        $service = app(InductionService::class);
        $code = 'IND'.Str::random(5);

        $first = $service->publish($this->companyId, ['code' => $code, 'title' => 'v1',
            'audience' => 'EMPLOYEE', 'content' => [['heading' => 'A', 'body' => 'B']]]);

        $service->complete($this->companyId, $first['uuid'],
            ['subject_type' => 'EMPLOYEE', 'person_name' => 'Early Person', 'signature' => 'x']);

        $second = $service->publish($this->companyId, ['code' => $code, 'title' => 'v2',
            'audience' => 'EMPLOYEE', 'content' => [['heading' => 'Changed', 'body' => 'New hazard']]]);

        $this->assertSame(2, $second['version']);

        // The completion must keep pointing at what that person was actually shown.
        $this->assertSame(1, (int) DB::table('sheq_induction_completions')
            ->where('company_id', $this->companyId)->where('person_name', 'Early Person')
            ->value('induction_version'));
    }

    // ── Sector library ────────────────────────────────────────────────────────────────────────

    public function test_a_mine_is_shown_mining_content_not_the_generic_induction(): void
    {
        if (! DB::table('sheq_inductions')->whereNull('company_id')
            ->where('sector', 'MINING')->exists()) {
            $this->markTestSkipped('The induction library is not installed.');
        }

        $manifest = (new PackLoader)
            ->load(base_path('resources/regulatory-packs/za-mhsa'));

        $packId = DB::table('sheq_regulatory_packs')->where('pack_id', 'za-mhsa')->value('id');

        if ($packId === null) {
            $this->markTestSkipped('The MHSA pack is not installed.');
        }

        DB::table('sheq_regulatory_pack_activations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'pack_version_id' => DB::table('sheq_regulatory_pack_versions')
                ->where('pack_id', $packId)->value('id'),
            'status' => 'ACTIVE', 'activated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $presented = app(InductionService::class)->present($this->companyId, 'VISITOR');

        // The whole point of a sector library: a mine visitor is told about self-rescuers and the
        // right to leave a dangerous working place; a warehouse visitor never sees either.
        $this->assertNotNull($presented);
        $this->assertStringContainsString('MIN', $presented['code']);
        $this->assertStringContainsString('self-rescuer', strtolower(json_encode($presented['content'])));
    }

    public function test_a_company_with_no_pack_gets_the_generic_induction(): void
    {
        if (! DB::table('sheq_inductions')->whereNull('company_id')->exists()) {
            $this->markTestSkipped('The induction library is not installed.');
        }

        $presented = app(InductionService::class)->present($this->companyId, 'VISITOR');

        $this->assertNotNull($presented);
        // Falls back rather than showing nothing, and certainly rather than showing mining content
        // to an office.
        $this->assertSame('GEN-VISITOR', $presented['code']);
    }

    public function test_a_tenants_own_induction_beats_the_shipped_library(): void
    {
        if (! DB::table('sheq_inductions')->whereNull('company_id')->exists()) {
            $this->markTestSkipped('The induction library is not installed.');
        }

        app(InductionService::class)->publish($this->companyId, [
            'code' => 'OURS'.Str::random(4), 'title' => 'Our own induction',
            'audience' => 'VISITOR',
            'content' => [['heading' => 'Ours', 'body' => 'Site-specific content.']],
        ]);

        $presented = app(InductionService::class)->present($this->companyId, 'VISITOR');

        // They know their own site. The library is a starting point, not an override.
        $this->assertSame('Our own induction', $presented['title']);
    }

    public function test_every_library_induction_carries_an_nda_and_a_legal_basis(): void
    {
        $shared = DB::table('sheq_inductions')->whereNull('company_id')
            ->where('is_active', true)->get(['code', 'nda_text', 'basis', 'requires_signature']);

        if ($shared->isEmpty()) {
            $this->markTestSkipped('The induction library is not installed.');
        }

        foreach ($shared as $induction) {
            $this->assertNotEmpty($induction->nda_text,
                "{$induction->code} has no agreement text for the person to accept.");
            // A practitioner reviewing the library must be able to check the content against the
            // regulation it claims to implement.
            $this->assertNotEmpty($induction->basis,
                "{$induction->code} does not cite what it is based on.");
            $this->assertTrue((bool) $induction->requires_signature);
        }
    }

    // ── Bulk import ───────────────────────────────────────────────────────────────────────────

    public function test_the_template_carries_instructions_and_worked_examples(): void
    {
        $rows = app(BulkImportService::class)->templateRows('CONTRACTOR_WORKERS');

        $flat = json_encode($rows);
        $this->assertStringContainsString('TEXT in Excel', $flat);
        $this->assertStringContainsString('id_number', $flat);
        // Two filled example rows, so nobody has to guess the format.
        $this->assertStringContainsString('8501015800083', $flat);
    }

    public function test_excel_mangled_id_numbers_are_explained_not_just_rejected(): void
    {
        $result = app(BulkImportService::class)->validate($this->companyId, 'CONTRACTOR_WORKERS', [
            ['contractor_name', 'full_name', 'id_number'],
            ['Nobody Ltd', 'Test Person', '8.50102E+12'],
        ]);

        $errors = json_encode($result['errors']);
        // The single most common real failure, and the message names the fix.
        $this->assertStringContainsString('Excel reformatted', $errors);
        $this->assertStringContainsString('row', strtolower($errors));
    }

    public function test_duplicate_rows_within_the_file_are_caught_before_writing(): void
    {
        $result = app(BulkImportService::class)->validate($this->companyId, 'CONTRACTOR_WORKERS', [
            ['contractor_name', 'full_name', 'id_number'],
            ['Nobody Ltd', 'First Person', '8501015800083'],
            ['Nobody Ltd', 'Second Person', '8501015800083'],
        ]);

        // No database constraint would catch this until half the rows were already written.
        $this->assertStringContainsString('also appears on row', json_encode($result['errors']));
    }

    public function test_validation_writes_nothing(): void
    {
        $before = DB::table('sheq_ppe_items')->where('company_id', $this->companyId)->count();

        app(BulkImportService::class)->validate($this->companyId, 'PPE_ITEMS', [
            ['code', 'name'],
            ['BOOT-'.Str::random(4), 'Safety boots'],
        ]);

        $this->assertSame($before,
            DB::table('sheq_ppe_items')->where('company_id', $this->companyId)->count());
    }

    public function test_a_valid_ppe_sheet_imports(): void
    {
        $code = 'BOOT-'.Str::random(4);

        $result = app(BulkImportService::class)->import($this->companyId, 'PPE_ITEMS', [
            ['code', 'name', 'category', 'lifespan_months', 'requires_size'],
            [$code, 'Safety boots', 'FOOT', '12', 'yes'],
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertTrue((bool) DB::table('sheq_ppe_items')
            ->where('company_id', $this->companyId)->where('code', $code)->value('requires_size'));
    }

    // ── Integration catalogue ─────────────────────────────────────────────────────────────────

    public function test_every_advertised_provider_has_a_working_adapter(): void
    {
        $manager = app(AccessIntegrationManager::class);

        foreach ($manager->catalogue() as $entry) {
            $this->assertContains($entry['provider'], $manager->providers(),
                "{$entry['provider']} is offered in the catalogue but has no adapter.");

            $record = (object) [
                'id' => 1, 'company_id' => $this->companyId, 'provider' => $entry['provider'],
                'api_endpoint' => 'https://example.test', 'api_key' => 'k', 'api_secret' => 's',
                'additional_config' => '{}', 'site_id' => null, 'enforce_access' => false,
                'last_sync_at' => null,
            ];

            // Resolvable and constructible: the check that catches a typo in the map before a
            // customer hits it while configuring a live gate.
            $this->assertNotNull($manager->resolveAdapter($record),
                "{$entry['provider']} could not be resolved to an adapter.");
        }
    }
}
