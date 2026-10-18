<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use App\Modules\Operations\Sheq\Services\SupplierOnboardingService;
use App\Modules\Operations\Sheq\Services\ToolboxTalkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Three answers the module computed for nobody.
 *
 * `ToolboxTalkService::lastAttendedBy()` — the talks screen reported "68% reached" and stopped. A
 * supervisor cannot brief a percentage, and the question the figure raises, "who is the other 32%
 * and when did they last sit one", was one the module held the answer to and could not be asked.
 *
 * `MedicalSurveillanceService::clinicalDetail()` — the register computed `canReadClinical` from
 * `sheq.pii.read` and used it only to decide whether the *capture* form showed a findings box. An
 * occupational health practitioner could write a finding into the system and never read one back.
 *
 * `SupplierOnboardingService::mayEngage()` — the supplier screen showed a status, a score and a
 * missing-document list and left a buyer to work out the verdict. An approval that has expired
 * still reads APPROVED, and expired evidence does not change the status at all, so "APPROVED,
 * score 80" would have engaged a supplier whose insurance had lapsed.
 */
class PeopleAnswersTest extends TestCase
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
        $this->user = $this->userWith(['sheq.configure', 'sheq.competency.manage', 'sheq.contractor.manage']);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ans-'.Str::random(8).'@example.test',
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

    private function person(string $first = 'Zodwa'): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'first_name' => $first, 'surname' => Str::random(6), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Toolbox talks ─────────────────────────────────────────────────────────────────────────

    public function test_the_people_the_coverage_figure_leaves_out_are_named(): void
    {
        $attended = $this->person('Attended');
        $missed = $this->person('Missed');

        app(ToolboxTalkService::class)->record($this->companyId, [
            'topic' => 'Ladders', 'held_on' => Carbon::today()->toDateString(),
            'attendees' => [['hr_person_id' => $attended, 'name' => 'Attended Person']],
        ], $this->user->id);

        $notReached = $this->actingAs($this->user)->get('/sheq/talks')
            ->viewData('page')['props']['notReached'];

        $names = array_column($notReached['people'], 'id');

        $this->assertContains($missed, $names);
        $this->assertNotContains($attended, $names);
    }

    public function test_somebody_who_has_never_attended_is_distinguished_from_somebody_who_missed_this_month(): void
    {
        $never = $this->person('Never');
        $lapsed = $this->person('Lapsed');

        // Last month, so they are not reached *this* month but have attended one before.
        app(ToolboxTalkService::class)->record($this->companyId, [
            'topic' => 'Hand tools', 'held_on' => Carbon::today()->subMonths(2)->toDateString(),
            'attendees' => [['hr_person_id' => $lapsed, 'name' => 'Lapsed Person']],
        ], $this->user->id);

        $people = collect($this->actingAs($this->user)->get('/sheq/talks')
            ->viewData('page')['props']['notReached']['people'])->keyBy('id');

        // Two different conversations, and the screen has to be able to tell them apart.
        $this->assertNull($people[$never]['last']);
        $this->assertSame('Hand tools', $people[$lapsed]['last']['topic']);
    }

    public function test_the_list_is_capped_but_the_total_is_not(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->person('Person'.$i);
        }

        $notReached = $this->actingAs($this->user)->get('/sheq/talks')
            ->viewData('page')['props']['notReached'];

        // The cap keeps `lastAttendedBy` from running once per employee on a page load; the total
        // is what tells a supervisor how much is behind the fifty names.
        $this->assertLessThanOrEqual(50, count($notReached['people']));
        $this->assertGreaterThanOrEqual(count($notReached['people']), $notReached['total']);
    }

    // ── Medical ───────────────────────────────────────────────────────────────────────────────

    private function medicalRecord(): string
    {
        $typeId = DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'M'.Str::random(5), 'name' => 'Audiometry',
            'examination_type' => 'PERIODIC', 'frequency_months' => 12,
            'blocks_work_when_unfit' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Through the service, so the fields are encrypted exactly as a real examination's are.
        // Writing the ciphertext by hand in a test is how a decryption test starts passing against
        // a value nothing in production would ever produce.
        $record = app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $typeId,
            'hr_person_id' => $this->person(),
            'examined_on' => Carbon::today()->subMonth()->toDateString(),
            'expires_on' => Carbon::today()->addDays(10)->toDateString(),
            'fitness_outcome' => 'FIT_WITH_RESTRICTION',
            'restrictions' => 'No work above 85 dB without protection',
            'clinical_findings' => 'Mild high-frequency loss, left ear',
            'practitioner_name' => 'Dr N. Pillay',
        ], $this->user->id);

        return $record['uuid'];
    }

    public function test_a_practitioner_can_read_back_what_was_recorded(): void
    {
        $uuid = $this->medicalRecord();
        $reader = $this->userWith(['sheq.pii.read']);

        $detail = $this->actingAs($reader)->getJson("/sheq/medical/{$uuid}/clinical")
            ->assertOk()->json();

        // Recording health information that cannot be retrieved by the person responsible for it
        // is worse than not recording it.
        $this->assertSame('Mild high-frequency loss, left ear', $detail['clinical_findings']);
        $this->assertSame('No work above 85 dB without protection', $detail['restrictions']);
        $this->assertSame('Dr N. Pillay', $detail['practitioner_name']);
    }

    public function test_clinical_detail_is_refused_without_the_pii_entitlement(): void
    {
        $uuid = $this->medicalRecord();

        // The register's own user can schedule around an examination. Reading the findings is a
        // separate entitlement, and this test is the one that keeps it separate.
        $this->actingAs($this->user)->getJson("/sheq/medical/{$uuid}/clinical")->assertForbidden();
    }

    public function test_reading_clinical_detail_is_recorded(): void
    {
        $uuid = $this->medicalRecord();
        $reader = $this->userWith(['sheq.pii.read']);

        $this->actingAs($reader)->getJson("/sheq/medical/{$uuid}/clinical")->assertOk();

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'medical.clinical_detail.read')
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry, 'The screen tells the reader this is recorded; it must be.');
        $this->assertSame($reader->id, (int) $entry->actor_id);
        // No clinical content in the log: an audit trail that copies the finding into itself
        // defeats the encryption it is auditing.
        $this->assertStringNotContainsString('high-frequency', (string) $entry->reason);
        $this->assertNull($entry->after);
    }

    public function test_another_tenants_medical_record_cannot_be_read(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'ans-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $typeId = DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'code' => 'X'.Str::random(5), 'name' => 'Theirs',
            'examination_type' => 'PERIODIC', 'frequency_months' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) Str::uuid();

        DB::table('sheq_medical_records')->insert([
            'uuid' => $uuid, 'company_id' => $other, 'medical_type_id' => $typeId,
            'hr_person_id' => 1, 'examined_on' => Carbon::today()->toDateString(),
            'fitness_outcome' => 'FIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $reader = $this->userWith(['sheq.pii.read']);

        $this->actingAs($reader)->getJson("/sheq/medical/{$uuid}/clinical")
            ->assertStatus(500);
    }

    // ── Suppliers ─────────────────────────────────────────────────────────────────────────────

    public function test_an_expired_approval_is_not_cleared_even_though_it_still_reads_approved(): void
    {
        $service = app(SupplierOnboardingService::class);

        $assessment = $service->invite($this->companyId, [
            'supplier_name' => 'Acme Scaffolding', 'risk_category' => 'LOW',
            'contact_email' => 'acme@example.test',
        ], $this->user->id);

        DB::table('sheq_supplier_assessments')
            ->where('company_id', $this->companyId)->where('uuid', $assessment['uuid'])
            ->update([
                'status' => 'APPROVED',
                'valid_until' => Carbon::today()->subDay()->toDateString(),
            ]);

        $row = collect($this->actingAs($this->user)->get('/sheq/suppliers')
            ->viewData('page')['props']['assessments'])->firstWhere('uuid', $assessment['uuid']);

        // The status says APPROVED. The verdict has to say otherwise, or a buyer engages a
        // supplier whose approval ran out yesterday.
        $this->assertSame('APPROVED', $row['status']);
        $this->assertFalse($row['may_engage']);
        $this->assertNotEmpty($row['engage_reasons']);
    }

    // ── The dashboard, and the contractor gate ────────────────────────────────────────────────

    public function test_the_dashboard_says_how_many_people_are_off_work_injured(): void
    {
        $incidentId = (int) DB::table('sheq_incidents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'incident_type' => 'INJURY',
            'description' => 'Crush injury to the hand', 'status' => 'OPEN',
            'occurred_at' => Carbon::now()->subWeeks(2), 'reported_at' => Carbon::now()->subWeeks(2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_injury_cases')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $incidentId, 'reference' => 'IC-'.Str::random(6),
            'hr_person_id' => $this->person('Injured'), 'status' => 'OPEN',
            'injured_on' => Carbon::today()->subWeeks(2)->toDateString(),
            // Should have been back a week ago and nobody recorded a return.
            'expected_return_on' => Carbon::today()->subWeek()->toDateString(),
            'days_lost' => 10, 'days_restricted' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attention = collect($this->actingAs($this->user)->get('/sheq')
            ->viewData('page')['props']['attention'])->keyBy('key');

        $this->assertSame(1, $attention['injury_cases_away']['count']);
        /*
         * The actionable half. An expected return date that has passed with no return recorded
         * means either the person is still off and the days lost are understated — which
         * understates every severity rate computed from them — or they came back and nobody wrote
         * it down.
         */
        $this->assertSame(1, $attention['injury_returns_overdue']['count']);
    }

    public function test_the_contractor_profile_states_whether_they_may_work(): void
    {
        $contractor = app(\App\Modules\Operations\Sheq\Services\ContractorService::class)
            ->register($this->companyId, ['name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING'],
                $this->user->id);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props'];

        // A status chip alone makes a guard decide what amber means. The verdict and the reasons
        // are what ends the argument at the boom.
        $this->assertFalse($props['mayWork']['allowed']);
        $this->assertNotEmpty($props['mayWork']['reasons']);
        $this->assertSame($props['compliance']['status'], $props['mayWork']['status']);
    }

    public function test_an_invited_supplier_is_not_cleared_to_be_used(): void
    {
        $service = app(SupplierOnboardingService::class);

        $assessment = $service->invite($this->companyId, [
            'supplier_name' => 'Not assessed yet', 'risk_category' => 'HIGH',
        ], $this->user->id);

        $row = collect($this->actingAs($this->user)->get('/sheq/suppliers')
            ->viewData('page')['props']['assessments'])->firstWhere('uuid', $assessment['uuid']);

        $this->assertFalse($row['may_engage']);
    }
}
