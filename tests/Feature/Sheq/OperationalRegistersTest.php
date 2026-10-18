<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\EmergencyService;
use App\Modules\Operations\Sheq\Services\EnvironmentalService;
use App\Modules\Operations\Sheq\Services\EquipmentService;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use App\Modules\Operations\Sheq\Services\PpeService;
use App\Modules\Operations\Sheq\Services\SupplierOnboardingService;
use App\Modules\Operations\Sheq\Services\VisitorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PPE, medical surveillance, environmental, equipment, visitors, emergency and supplier onboarding.
 *
 * Each of these registers exists to answer a question after something has gone wrong — was the PPE
 * issued, was the person fit, was the extinguisher checked, who was still inside, was the contractor
 * assessed. The tests are written against those questions rather than against CRUD.
 */
class OperationalRegistersTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'R'.Str::random(6), 'name' => 'Plant', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'reg-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(): int
    {
        return DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Test',
            'surname' => 'Worker'.Str::random(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── PPE ───────────────────────────────────────────────────────────────────────────────────

    private function ppeItem(array $overrides = []): int
    {
        return DB::table('sheq_ppe_items')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'PPE'.Str::random(5), 'name' => 'Safety boots',
            'category' => 'FOOT', 'unit' => 'PAIR', 'lifespan_months' => 12,
            'requires_size' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_ppe_issue_computes_the_replacement_date_from_service_life(): void
    {
        $itemId = $this->ppeItem(['lifespan_months' => 12]);

        $issue = app(PpeService::class)->issue($this->companyId, [
            'ppe_item_id' => $itemId, 'hr_person_id' => $this->person(),
            'size' => '9', 'issued_on' => '2026-01-15',
        ], $this->userId);

        // The person handing over a helmet cannot judge a five-year-old polymer shell by looking
        // at it, so the date is computed rather than typed.
        $this->assertSame('2027-01-15', $issue['replace_due']);
    }

    public function test_ppe_that_needs_a_size_cannot_be_issued_without_one(): void
    {
        $itemId = $this->ppeItem(['requires_size' => true]);

        $this->expectExceptionMessageMatches('/issued by size/');

        app(PpeService::class)->issue($this->companyId, [
            'ppe_item_id' => $itemId, 'hr_person_id' => $this->person(),
        ], $this->userId);
    }

    public function test_ppe_must_be_issued_to_somebody(): void
    {
        $itemId = $this->ppeItem(['requires_size' => false]);

        $this->expectExceptionMessageMatches('/must be issued to somebody/');

        app(PpeService::class)->issue($this->companyId, ['ppe_item_id' => $itemId], $this->userId);
    }

    public function test_issuing_decrements_stock_and_refuses_to_go_negative(): void
    {
        $itemId = $this->ppeItem(['requires_size' => false]);

        DB::table('sheq_ppe_stock')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'ppe_item_id' => $itemId, 'quantity_on_hand' => 1, 'reorder_level' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(PpeService::class)->issue($this->companyId,
            ['ppe_item_id' => $itemId, 'hr_person_id' => $this->person()], $this->userId);

        $this->assertSame(0, (int) DB::table('sheq_ppe_stock')
            ->where('ppe_item_id', $itemId)->value('quantity_on_hand'));

        // Two storemen issuing the last pair must not both succeed — the register would say two
        // people are protected when one is.
        $this->expectExceptionMessageMatches('/left in stock/');

        app(PpeService::class)->issue($this->companyId,
            ['ppe_item_id' => $itemId, 'hr_person_id' => $this->person()], $this->userId);
    }

    public function test_damaged_ppe_does_not_go_back_on_the_shelf(): void
    {
        $itemId = $this->ppeItem(['requires_size' => false]);

        DB::table('sheq_ppe_stock')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'ppe_item_id' => $itemId, 'quantity_on_hand' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $issue = app(PpeService::class)->issue($this->companyId,
            ['ppe_item_id' => $itemId, 'hr_person_id' => $this->person()], $this->userId);

        app(PpeService::class)->markReturned($this->companyId, $issue['uuid'], 'DAMAGED');

        // A damaged harness returning to stock is how somebody ends up issued one that failed.
        $this->assertSame(4, (int) DB::table('sheq_ppe_stock')
            ->where('ppe_item_id', $itemId)->value('quantity_on_hand'));
    }

    // ── Medical surveillance ──────────────────────────────────────────────────────────────────

    private function medicalType(array $overrides = []): int
    {
        return DB::table('sheq_medical_types')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'MED'.Str::random(5), 'name' => 'Audiometry',
            'examination_type' => 'AUDIOMETRY', 'frequency_months' => 12,
            'blocks_work_when_unfit' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_clinical_findings_are_encrypted_but_the_fitness_outcome_is_not(): void
    {
        $personId = $this->person();

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $this->medicalType(), 'hr_person_id' => $personId,
            'fitness_outcome' => 'FIT',
            'clinical_findings' => 'Threshold shift at 4kHz, left ear.',
        ], $this->userId);

        $raw = DB::table('sheq_medical_records')->where('hr_person_id', $personId)
            ->first(['clinical_findings', 'fitness_outcome']);

        // A supervisor must be able to read the outcome to schedule work; they have no business
        // reading the audiogram.
        $this->assertStringNotContainsString('Threshold shift', (string) $raw->clinical_findings);
        $this->assertSame('FIT', $raw->fitness_outcome);
    }

    public function test_the_scheduling_view_does_not_return_clinical_detail_at_all(): void
    {
        $personId = $this->person();

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $this->medicalType(), 'hr_person_id' => $personId,
            'fitness_outcome' => 'FIT', 'clinical_findings' => 'Sensitive.',
        ], $this->userId);

        $fitness = app(MedicalSurveillanceService::class)
            ->fitnessFor($this->companyId, $personId);

        // Not merely hidden in the UI: a field that is never fetched cannot leak through a debug
        // dump, an API projection or a careless dd().
        $this->assertArrayNotHasKey('clinical_findings', $fitness[0]);
        $this->assertSame('FIT', $fitness[0]['fitness_outcome']);
    }

    public function test_a_restricted_fitness_must_say_what_the_restriction_is(): void
    {
        $this->expectExceptionMessageMatches('/say what the restriction is/');

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $this->medicalType(), 'hr_person_id' => $this->person(),
            'fitness_outcome' => 'FIT_WITH_RESTRICTION',
        ], $this->userId);
    }

    public function test_an_unfit_result_blocks_work(): void
    {
        $personId = $this->person();

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $this->medicalType(), 'hr_person_id' => $personId,
            'fitness_outcome' => 'UNFIT',
        ], $this->userId);

        $cleared = app(MedicalSurveillanceService::class)
            ->isClearedForWork($this->companyId, $personId);

        $this->assertFalse($cleared['cleared']);
        $this->assertNotEmpty($cleared['reasons']);
    }

    public function test_an_expired_examination_is_not_a_valid_clearance(): void
    {
        $personId = $this->person();

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $this->medicalType(), 'hr_person_id' => $personId,
            'fitness_outcome' => 'FIT',
            'examined_on' => Carbon::today()->subYears(3)->toDateString(),
            'expires_on' => Carbon::today()->subYears(2)->toDateString(),
        ], $this->userId);

        // An audiogram from three years ago says nothing about hearing today.
        $this->assertFalse(app(MedicalSurveillanceService::class)
            ->isClearedForWork($this->companyId, $personId)['cleared']);
    }

    public function test_only_the_latest_examination_of_each_type_counts(): void
    {
        $personId = $this->person();
        $typeId = $this->medicalType();
        $service = app(MedicalSurveillanceService::class);

        $service->record($this->companyId, [
            'medical_type_id' => $typeId, 'hr_person_id' => $personId,
            'fitness_outcome' => 'UNFIT',
            'examined_on' => Carbon::today()->subYear()->toDateString(),
        ], $this->userId);

        $service->record($this->companyId, [
            'medical_type_id' => $typeId, 'hr_person_id' => $personId,
            'fitness_outcome' => 'FIT', 'examined_on' => Carbon::today()->toDateString(),
        ], $this->userId);

        // Last year's "unfit" is not this year's status.
        $fitness = $service->fitnessFor($this->companyId, $personId);

        $this->assertCount(1, $fitness);
        $this->assertSame('FIT', $fitness[0]['fitness_outcome']);
    }

    // ── Environmental ─────────────────────────────────────────────────────────────────────────

    private function meter(array $overrides = []): int
    {
        return DB::table('sheq_environmental_meters')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId, 'code' => 'M'.Str::random(6),
            'name' => 'Main electricity', 'aspect' => 'ELECTRICITY', 'unit' => 'kWh',
            'carbon_factor' => 1.06, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_a_reading_stamps_the_carbon_factor_it_was_computed_under(): void
    {
        $meterId = $this->meter(['carbon_factor' => 1.06]);

        $reading = app(EnvironmentalService::class)->record($this->companyId, [
            'meter_id' => $meterId, 'value' => 1000, 'reading_date' => '2026-03-01',
        ], $this->userId);

        $this->assertEqualsWithDelta(1060.0, $reading['co2e_kg'], 0.01);

        // The grid mix changes. Applying the factor at report time would silently restate last
        // year's published total every time the constant was updated.
        $this->assertEqualsWithDelta(1.06, (float) DB::table('sheq_environmental_readings')
            ->where('uuid', $reading['uuid'])->value('carbon_factor_applied'), 0.0001);
    }

    public function test_two_readings_for_one_meter_on_one_day_are_refused(): void
    {
        $meterId = $this->meter();

        app(EnvironmentalService::class)->record($this->companyId,
            ['meter_id' => $meterId, 'value' => 100, 'reading_date' => '2026-03-01'], $this->userId);

        $this->expectExceptionMessageMatches('/already exists/');

        app(EnvironmentalService::class)->record($this->companyId,
            ['meter_id' => $meterId, 'value' => 120, 'reading_date' => '2026-03-01'], $this->userId);
    }

    public function test_a_negative_reading_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/non-negative/');

        app(EnvironmentalService::class)->record($this->companyId,
            ['meter_id' => $this->meter(), 'value' => -50], $this->userId);
    }

    public function test_meters_without_a_factor_are_named_rather_than_silently_excluded(): void
    {
        $this->meter(['carbon_factor' => null, 'name' => 'Site diesel tank', 'aspect' => 'DIESEL']);

        $summary = app(EnvironmentalService::class)->summary($this->companyId,
            '2026-01-01', '2026-12-31', $this->siteId);

        // A carbon total that quietly omits a site's diesel is worse than one that says which
        // meters are missing a factor.
        $this->assertContains('Site diesel tank', $summary['meters_without_factor']);
    }

    public function test_a_meter_nobody_has_read_is_surfaced(): void
    {
        $this->meter(['name' => 'Forgotten submeter']);

        $stale = app(EnvironmentalService::class)->staleMeters($this->companyId);

        // The usual failure is not a wrong number — it is a meter nobody has read since the person
        // who used to do it left.
        $this->assertContains('Forgotten submeter', array_column($stale, 'name'));
    }

    // ── Equipment ─────────────────────────────────────────────────────────────────────────────

    public function test_equipment_gets_a_stable_scannable_code(): void
    {
        $service = app(EquipmentService::class);

        $first = $service->register($this->companyId, [
            'asset_tag' => 'FE-001', 'equipment_type' => 'FIRE_EXTINGUISHER',
            'name' => 'Extinguisher — workshop', 'site_id' => $this->siteId,
        ], $this->userId);

        // Derived from the tag, so re-printing a torn label produces the same code and the history
        // stays attached to the extinguisher rather than to the sticker.
        $found = $service->findByCode($this->companyId, $first['qr_code']);

        $this->assertSame('FE-001', $found['asset_tag']);
    }

    public function test_statutory_inspection_intervals_are_applied_by_type(): void
    {
        $service = app(EquipmentService::class);

        $service->register($this->companyId, [
            'asset_tag' => 'FE-002', 'equipment_type' => 'FIRE_EXTINGUISHER',
        ], $this->userId);

        $service->register($this->companyId, [
            'asset_tag' => 'HN-001', 'equipment_type' => 'HARNESS',
        ], $this->userId);

        $this->assertSame(30, (int) DB::table('sheq_equipment')
            ->where('asset_tag', 'FE-002')->value('inspection_frequency_days'));
        // Construction Regulation 10 — six-monthly on fall-arrest equipment.
        $this->assertSame(180, (int) DB::table('sheq_equipment')
            ->where('asset_tag', 'HN-001')->value('inspection_frequency_days'));
    }

    public function test_a_failed_inspection_takes_equipment_out_of_service_immediately(): void
    {
        $service = app(EquipmentService::class);

        $equipment = $service->register($this->companyId, [
            'asset_tag' => 'FE-003', 'equipment_type' => 'FIRE_EXTINGUISHER',
        ], $this->userId);

        $result = $service->recordInspection($this->companyId, $equipment['uuid'],
            ['passed' => false], $this->userId);

        // "In service with a note" is how somebody grabs a discharged extinguisher during a fire.
        $this->assertSame('OUT_OF_SERVICE', $result['status']);
    }

    public function test_a_duplicate_tag_is_refused(): void
    {
        $service = app(EquipmentService::class);

        $service->register($this->companyId,
            ['asset_tag' => 'DUP-1', 'equipment_type' => 'LADDER'], $this->userId);

        $this->expectExceptionMessageMatches('/already in use/');

        $service->register($this->companyId,
            ['asset_tag' => 'DUP-1', 'equipment_type' => 'LADDER'], $this->userId);
    }

    // ── Visitors ──────────────────────────────────────────────────────────────────────────────

    private function visitorWorkflow(): void
    {
        $workflowId = DB::table('sheq_approval_workflows')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'subject_type' => 'VISITOR', 'name' => 'Host approval',
            'version' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_approval_steps')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'workflow_id' => $workflowId, 'position' => 1, 'name' => 'Manager approves',
            'approver_type' => 'ANY_APPROVER', 'quorum' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_visit_request_opens_an_approval(): void
    {
        $this->visitorWorkflow();

        $visit = app(VisitorService::class)->request($this->companyId, [
            'visitor_name' => 'Thandi Mahlangu', 'visitor_company' => 'Acme Engineering',
            'site_id' => $this->siteId, 'purpose' => 'Pump inspection',
        ], $this->userId);

        $this->assertNotEmpty($visit['approval_uuid']);
        $this->assertSame('REQUESTED', $visit['status']);
    }

    public function test_a_visit_cannot_be_inducted_before_it_is_approved(): void
    {
        $this->visitorWorkflow();

        $visit = app(VisitorService::class)->request($this->companyId,
            ['visitor_name' => 'Unapproved Person', 'site_id' => $this->siteId], $this->userId);

        $this->expectExceptionMessageMatches('/must be approved before induction/');

        app(VisitorService::class)->recordInduction($this->companyId, $visit['uuid'],
            ['signature' => 'data:image/png;base64,xxx']);
    }

    public function test_a_visitor_cannot_sign_in_without_induction(): void
    {
        $this->visitorWorkflow();
        $service = app(VisitorService::class);

        $visit = $service->request($this->companyId,
            ['visitor_name' => 'Uninducted Person', 'site_id' => $this->siteId], $this->userId);

        $service->applyApproval($this->companyId, $visit['id'], true);

        // A visitor is the person on site least likely to survive an evacuation, because they do
        // not know where the assembly point is and nobody will notice they are missing.
        $this->expectExceptionMessageMatches('/not been inducted/');

        $service->signIn($this->companyId, $visit['uuid']);
    }

    public function test_an_induction_must_be_signed(): void
    {
        $this->visitorWorkflow();
        $service = app(VisitorService::class);

        $visit = $service->request($this->companyId,
            ['visitor_name' => 'Signature Test', 'site_id' => $this->siteId], $this->userId);

        $service->applyApproval($this->companyId, $visit['id'], true);

        $this->expectExceptionMessageMatches('/must be signed/');

        $service->recordInduction($this->companyId, $visit['uuid'], []);
    }

    public function test_the_roll_call_shows_who_is_still_inside(): void
    {
        $this->visitorWorkflow();
        $service = app(VisitorService::class);

        $visit = $service->request($this->companyId,
            ['visitor_name' => 'On Site Person', 'site_id' => $this->siteId], $this->userId);

        $service->applyApproval($this->companyId, $visit['id'], true);
        $service->recordInduction($this->companyId, $visit['uuid'],
            ['signature' => 'data:image/png;base64,xxx']);
        $service->signIn($this->companyId, $visit['uuid']);

        $onSite = $service->onSite($this->companyId, $this->siteId);
        $this->assertContains('On Site Person', array_column($onSite, 'visitor_name'));

        $service->signOut($this->companyId, $visit['uuid']);

        // The register's real purpose: after the alarm, the only question is who is still inside.
        $this->assertNotContains('On Site Person',
            array_column($service->onSite($this->companyId, $this->siteId), 'visitor_name'));
    }

    // ── Emergency ─────────────────────────────────────────────────────────────────────────────

    public function test_a_plan_needs_an_assembly_point(): void
    {
        $this->expectExceptionMessageMatches('/at least one assembly point/');

        app(EmergencyService::class)->createPlan($this->companyId, [
            'emergency_type' => 'FIRE', 'site_id' => $this->siteId,
        ], $this->userId);
    }

    public function test_a_drill_with_people_unaccounted_for_raises_an_action(): void
    {
        $plan = app(EmergencyService::class)->createPlan($this->companyId, [
            'emergency_type' => 'FIRE', 'site_id' => $this->siteId,
            'assembly_points' => [['name' => 'Car park A']],
        ], $this->userId);

        $drill = app(EmergencyService::class)->recordDrill($this->companyId, [
            'plan_id' => $plan['id'], 'site_id' => $this->siteId,
            'drill_type' => 'FIRE', 'evacuation_seconds' => 240,
            'participants' => 48, 'unaccounted' => 2,
        ], $this->userId);

        // An unaccounted person in a rehearsal is the clearest available warning that a real
        // evacuation will not account for everybody.
        $this->assertTrue($drill['action_raised']);
    }

    public function test_a_fast_drill_with_missing_people_is_not_a_pass(): void
    {
        app(EmergencyService::class)->recordDrill($this->companyId, [
            'site_id' => $this->siteId, 'drill_type' => 'FIRE',
            'evacuation_seconds' => 180, 'participants' => 30, 'unaccounted' => 1,
        ], $this->userId);

        $history = app(EmergencyService::class)->drillHistory($this->companyId, $this->siteId);

        // Recording only the time would let a three-minute evacuation with somebody missing read
        // as a success.
        $this->assertFalse($history[0]['passed']);
    }

    public function test_more_unaccounted_than_participants_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/cannot be right/');

        app(EmergencyService::class)->recordDrill($this->companyId, [
            'drill_type' => 'FIRE', 'participants' => 5, 'unaccounted' => 9,
        ], $this->userId);
    }

    public function test_a_plan_never_drilled_is_surfaced(): void
    {
        app(EmergencyService::class)->createPlan($this->companyId, [
            'emergency_type' => 'CHEMICAL_SPILL', 'name' => 'Spill response',
            'site_id' => $this->siteId, 'assembly_points' => [['name' => 'Gate 2']],
        ], $this->userId);

        $overdue = app(EmergencyService::class)->sitesOverdueForDrill($this->companyId);

        $this->assertContains('Spill response', array_column($overdue, 'plan'));
    }

    // ── Supplier onboarding ───────────────────────────────────────────────────────────────────

    public function test_required_evidence_follows_the_risk_category(): void
    {
        $service = app(SupplierOnboardingService::class);

        $low = $service->invite($this->companyId,
            ['supplier_name' => 'Stationery Co', 'risk_category' => 'LOW'], $this->userId);
        $high = $service->invite($this->companyId,
            ['supplier_name' => 'Rigging Co', 'risk_category' => 'HIGH'], $this->userId);

        // Demanding a full safety file from a stationery supplier is how the whole process gets
        // routed around.
        $this->assertCount(1, $low['required_documents']);
        $this->assertContains('SAFETY_FILE', $high['required_documents']);
        $this->assertContains('LETTER_OF_GOOD_STANDING', $high['required_documents']);
    }

    public function test_an_assessment_cannot_be_submitted_with_missing_evidence(): void
    {
        $assessment = app(SupplierOnboardingService::class)->invite($this->companyId,
            ['supplier_name' => 'Incomplete Co', 'risk_category' => 'MEDIUM'], $this->userId);

        $this->expectExceptionMessageMatches('/not ready/');

        app(SupplierOnboardingService::class)
            ->submitForApproval($this->companyId, $assessment['uuid'], $this->userId);
    }

    public function test_an_expired_document_invalidates_the_assessment(): void
    {
        $service = app(SupplierOnboardingService::class);

        $assessment = $service->invite($this->companyId,
            ['supplier_name' => 'Lapsed Co', 'risk_category' => 'LOW'], $this->userId);

        $service->attachDocument($this->companyId, $assessment['uuid'], [
            'doc_type' => 'COMPANY_REGISTRATION', 'file_id' => 1,
            'expires_on' => Carbon::today()->subDay()->toDateString(),
        ]);

        $state = $service->completeness($this->companyId, $assessment['uuid']);

        // Compliance is computed from the evidence, never stored as a flag somebody set once.
        $this->assertFalse($state['complete']);
        $this->assertContains('COMPANY_REGISTRATION', $state['expired']);
    }

    public function test_an_approval_expires_and_blocks_engagement(): void
    {
        $service = app(SupplierOnboardingService::class);

        $assessment = $service->invite($this->companyId,
            ['supplier_name' => 'Expired Approval Co', 'risk_category' => 'LOW'], $this->userId);

        $service->applyDecision($this->companyId, $assessment['id'], true, null, 12);

        DB::table('sheq_supplier_assessments')->where('id', $assessment['id'])
            ->update(['valid_until' => Carbon::today()->subDay()->toDateString()]);

        $verdict = $service->mayEngage($this->companyId, $assessment['uuid']);

        // A supplier approved on a letter that lapsed two years ago has not been assessed — they
        // have been remembered.
        $this->assertFalse($verdict['allowed']);
        $this->assertNotEmpty($verdict['reasons']);
    }

    public function test_an_approved_supplier_with_current_evidence_may_be_engaged(): void
    {
        $service = app(SupplierOnboardingService::class);

        $assessment = $service->invite($this->companyId,
            ['supplier_name' => 'Good Co', 'risk_category' => 'LOW'], $this->userId);

        $service->attachDocument($this->companyId, $assessment['uuid'], [
            'doc_type' => 'COMPANY_REGISTRATION', 'file_id' => 1,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ]);

        DB::table('sheq_supplier_documents')->where('assessment_id', $assessment['id'])
            ->update(['status' => 'ACCEPTED']);

        $service->applyDecision($this->companyId, $assessment['id'], true, null, 12);

        $this->assertTrue($service->mayEngage($this->companyId, $assessment['uuid'])['allowed']);
    }
}
