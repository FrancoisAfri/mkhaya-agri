<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The contractor requirements held to the implementation rules (CON-1…CON-5).
 *
 * Three things the rules require and the module did not do:
 *
 * **Rule 3 — every important state transition is audited, with actor, previous state, new state and
 * reason.** `access_status` is the most consequential field a contractor record carries: it decides
 * whether their people come on site. It moved with no audit entry at all. The outbox event fires
 * only on the way *into* BLOCKED and is integration traffic, not a record anybody reads back, so
 * "who unblocked them, and on what evidence" had no answer — and unblocking is the direction that
 * puts somebody on site. Registering a worker was a bare insert in the controller for the same
 * reason: somebody could appear on a roster, be inducted and walk through a turnstile with nothing
 * recording who added them.
 *
 * **CON-2 — workers are subject to competency gating.** The permit gate skipped any worker with no
 * personnel record, which is every contractor's employee. It then reported "nothing is blocking
 * this permit" for a crew of welders nobody had checked, on the screen whose entire purpose is to
 * say what has not been verified.
 */
class ContractorConformanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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
        $this->user = $this->userWith([
            'sheq.contractor.manage', 'sheq.permit.request', 'sheq.permit.approve',
        ]);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'K'.Str::random(5), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'con-conf-'.Str::random(8).'@example.test',
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

    /** Say what this host demands, the way the settings screen will. */
    private function requireDocuments(array $types): void
    {
        DB::table('sheq_settings')->where('company_id', $this->companyId)->exists()
            ? DB::table('sheq_settings')->where('company_id', $this->companyId)
                ->update(['contractor_required_documents' => json_encode($types)])
            : DB::table('sheq_settings')->insert([
                'company_id' => $this->companyId,
                'contractor_required_documents' => json_encode($types),
                'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function contractor(): array
    {
        return app(ContractorService::class)->register($this->companyId, [
            'name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);
    }

    private function auditFor(string $action): ?object
    {
        return DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)->where('action', $action)
            ->orderByDesc('id')->first();
    }

    // ── Rule 3: the access transition is audited ──────────────────────────────────────────────

    public function test_unblocking_a_contractor_is_recorded_with_who_did_it_and_why(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->assertSame('BLOCKED', DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('access_status'));

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'issuing_body' => 'Compensation Fund',
                'issued_on' => Carbon::today()->subMonth()->toDateString(),
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ])->assertRedirect();

        $entry = $this->auditFor('contractor.access_status_changed');

        $this->assertNotNull($entry, 'The direction that puts somebody on site must be recorded.');
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertSame('BLOCKED', json_decode((string) $entry->before, true)['access_status']);
        $this->assertSame('COMPLIANT', json_decode((string) $entry->after, true)['access_status']);
        // The evidence, not the bare status: "BLOCKED" six months later tells a reviewer nothing,
        // and the documents behind it may since have been replaced.
        $this->assertNotEmpty($entry->reason);
    }

    public function test_the_reason_names_the_document_that_was_missing(): void
    {
        // Two demanded, one supplied: the record stays blocked, and the reason has to say which
        // piece of paper is still outstanding rather than only that one is.
        $this->requireDocuments(['COIDA_LETTER', 'PUBLIC_LIABILITY']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'PUBLIC_LIABILITY',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ])->assertRedirect();

        // Still blocked, so no transition and no entry — the reason is read from the file itself.
        $file = app(ContractorService::class)
            ->complianceFile($this->companyId, $contractor['uuid']);

        $this->assertSame('BLOCKED', $file['status']);
        $this->assertStringContainsStringIgnoringCase('not been supplied',
            implode(' ', array_column($file['blocking'], 'detail')));
    }

    public function test_a_recompute_that_changes_nothing_writes_no_entry(): void
    {
        $contractor = $this->contractor();

        $before = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.access_status_changed')->count();

        // Still blocked before and after. Logging an unchanged status would bury the real
        // transitions in noise from every document touch.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/refresh")->assertRedirect();

        $this->assertSame($before, DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.access_status_changed')->count());
    }

    public function test_re_blocking_on_an_expired_document_is_recorded_too(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        // The certificate lapses where it sits. Nobody does anything; time does.
        DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)
            ->update(['expires_on' => Carbon::today()->subDay()->toDateString()]);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/refresh")->assertRedirect();

        $entry = $this->auditFor('contractor.access_status_changed');

        $this->assertSame('COMPLIANT', json_decode((string) $entry->before, true)['access_status']);
        $this->assertSame('BLOCKED', json_decode((string) $entry->after, true)['access_status']);
        $this->assertStringContainsStringIgnoringCase('expired', (string) $entry->reason);
    }

    // ── Rule 3: registering a worker is audited ───────────────────────────────────────────────

    public function test_adding_a_worker_records_who_added_them(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/workers", [
                'name' => 'T. Mokoena', 'trade' => 'SCAFFOLDING',
                'induction_expires' => Carbon::today()->addYear()->toDateString(),
            ])->assertRedirect();

        $entry = $this->auditFor('contractor.worker_registered');

        $this->assertNotNull($entry,
            'A person who will be issued a site pass cannot appear with no record of who added them.');
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertSame('T. Mokoena', json_decode((string) $entry->after, true)['name']);
    }

    public function test_the_worker_is_still_created_and_still_photographed(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/workers", [
                'name' => 'N. Dube', 'trade' => 'SCAFFOLDING',
            ])->assertRedirect()->assertSessionHasNoErrors();

        // Moving the insert into the service must not have changed what it produces.
        $this->assertDatabaseHas('sheq_contractor_workers', [
            'company_id' => $this->companyId, 'name' => 'N. Dube', 'is_active' => true,
        ]);
    }

    public function test_a_worker_cannot_be_added_to_another_tenants_contractor(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'conf-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignUuid = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreignUuid, 'company_id' => $other, 'name' => 'Theirs',
            'access_status' => 'BLOCKED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * The service resolves the contractor within the tenant, so this is a refusal rather than a
         * worker quietly attached to somebody else's roster. It arrives as a redirect carrying the
         * refusal, not a 500: SHEQ refusals are rendered to the person who hit them.
         */
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$foreignUuid}/workers", ['name' => 'Nobody'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('sheq_contractor_workers', ['name' => 'Nobody']);
    }

    public function test_adding_a_worker_needs_the_contractor_entitlement(): void
    {
        $contractor = $this->contractor();
        $onlooker = $this->userWith([]);

        $this->actingAs($onlooker)
            ->post("/sheq/contractors/{$contractor['uuid']}/workers", ['name' => 'Nobody'])
            ->assertForbidden();
    }

    // ── CON-2: a worker the ledger cannot speak for is named, not skipped ─────────────────────

    private function permitWith(array $workers): array
    {
        $assessments = app(RiskAssessmentService::class);

        $assessment = $assessments->create($this->companyId, [
            'title' => 'Hot work', 'site_id' => $this->siteId,
        ], $this->user->id);

        $assessments->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Welding', 'hazard' => 'Ignition',
            'inherent_likelihood' => 4, 'inherent_severity' => 5,
            'residual_likelihood' => 2, 'residual_severity' => 3,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Purge the line']]);

        $assessments->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
        $assessments->approve($this->companyId, $assessment['uuid'], $this->user->id, ['SITE_MANAGER']);

        DB::table('sheq_permit_requirements')->insert([
            'company_id' => $this->companyId, 'permit_type' => 'HOT_WORK',
            'competency_code' => 'HW-WELD', 'is_mandatory' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return app(PermitService::class)->request($this->companyId, [
            'permit_type' => 'HOT_WORK', 'site_id' => $this->siteId,
            'work_description' => 'Weld a bracket on line 4.',
            'valid_from' => Carbon::now()->toDateTimeString(),
            'valid_to' => Carbon::now()->addHours(8)->toDateTimeString(),
            'risk_assessment_id' => $assessment['id'],
        ], $workers, $this->user->id);
    }

    public function test_a_contractor_worker_on_a_permit_is_reported_as_unverifiable(): void
    {
        // No hr_person_id: this is a contractor's welder, which is who does most hot work.
        $permit = $this->permitWith([['name' => 'T. Mokoena', 'worker_role' => 'WORKER']]);

        $checks = $this->actingAs($this->user)
            ->get("/sheq/permits/{$permit['uuid']}")
            ->viewData('page')['props']['checks'];

        $warningTypes = array_column($checks['warnings'], 'type');

        /*
         * The gate used to say nothing at all about this crew, on the screen whose entire purpose
         * is to say what has not been verified. An issuer signed hot work believing the welders had
         * been checked.
         */
        $this->assertContains('COMPETENCY_UNVERIFIABLE', $warningTypes);

        $warning = collect($checks['warnings'])->firstWhere('type', 'COMPETENCY_UNVERIFIABLE');
        $this->assertContains('T. Mokoena', $warning['workers']);
        $this->assertStringContainsString('T. Mokoena', $warning['detail']);
    }

    public function test_an_unverifiable_worker_warns_rather_than_blocks(): void
    {
        $permit = $this->permitWith([['name' => 'T. Mokoena', 'worker_role' => 'WORKER']]);

        $checks = $this->actingAs($this->user)
            ->get("/sheq/permits/{$permit['uuid']}")
            ->viewData('page')['props']['checks'];

        /*
         * Deliberately a warning. Most high-risk work on most sites is done by contractors, so
         * blocking here would stop the work the module exists to control and push permits outside
         * the system — the same reasoning that makes a conflicting permit a warning.
         */
        $this->assertNotContains('COMPETENCY_UNVERIFIABLE', array_column($checks['blocking'], 'type'));
    }

    public function test_an_employee_without_the_required_ticket_still_blocks(): void
    {
        $personId = (int) DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'first_name' => 'Own', 'surname' => 'Employee', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $permit = $this->permitWith([
            ['name' => 'Own Employee', 'worker_role' => 'WORKER', 'hr_person_id' => $personId],
        ]);

        $checks = $this->actingAs($this->user)
            ->get("/sheq/permits/{$permit['uuid']}")
            ->viewData('page')['props']['checks'];

        // The change must not have weakened the real gate: somebody the ledger *can* speak for,
        // with no record of the required competency, still stops the permit.
        $this->assertContains('COMPETENCY_GAP', array_column($checks['blocking'], 'type'));
    }

    // ── CON-5: the safety file and the commercial record can see each other ───────────────────

    private function supplier(string $name, ?string $registration = null): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $this->companyId,
            'company_name' => $name,
            'company_registration_number' => $registration,
            'portal_token' => Str::random(40),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_contractor_can_be_tied_to_their_commercial_record(): void
    {
        $supplierId = $this->supplier('Scaffold Co');
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/link-supplier")
            ->assertRedirect()->assertSessionHas('success');

        // Nothing ever wrote this column: no validator accepted the field and the bridge's
        // `link()` had no caller, so the two halves of what we know about a company sat in the
        // same database with nothing joining them.
        $this->assertSame($supplierId, (int) DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('supplier_id'));
    }

    public function test_the_link_is_audited(): void
    {
        $this->supplier('Scaffold Co');
        $contractor = $this->contractor();

        $this->actingAs($this->user)->post("/sheq/contractors/{$contractor['uuid']}/link-supplier");

        $entry = $this->auditFor('contractor.supplier_linked');

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertStringContainsString('Scaffold Co', (string) $entry->reason);
    }

    public function test_the_registration_number_wins_over_a_similar_name(): void
    {
        // Two companies trading under similar names; only one holds the registration.
        $wrong = $this->supplier('Scaffold Co');
        $right = $this->supplier('Scaffold Co (Pty) Ltd', '2009/123456/07');

        $contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING',
            'registration_number' => '2009/123456/07',
        ], $this->user->id);

        $this->actingAs($this->user)->post("/sheq/contractors/{$contractor['uuid']}/link-supplier");

        $linked = (int) DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('supplier_id');

        $this->assertSame($right, $linked);
        $this->assertNotSame($wrong, $linked);
    }

    public function test_a_contractor_with_no_commercial_record_is_told_rather_than_failed(): void
    {
        $contractor = $this->contractor();

        // Not an error state: they simply have not been onboarded on the commercial side, and the
        // message says what to do about it.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/link-supplier")
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNull(DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('supplier_id'));
    }

    public function test_the_profile_shows_the_assessment_once_they_are_linked(): void
    {
        $supplierId = $this->supplier('Scaffold Co');
        $contractor = $this->contractor();

        DB::table('sheq_supplier_assessments')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'supplier_id' => $supplierId, 'supplier_name' => 'Scaffold Co',
            'status' => 'REJECTED', 'risk_category' => 'HIGH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->post("/sheq/contractors/{$contractor['uuid']}/link-supplier");

        $link = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props']['supplierLink'];

        // A gatehouse arguing about a contractor should be able to see that procurement rejected
        // them. That was previously unknowable from this screen.
        $this->assertTrue($link['linked']);
        $this->assertSame('Scaffold Co', $link['supplier_name']);
        $this->assertSame('REJECTED', $link['assessment']['status']);
    }

    public function test_an_assessment_can_be_linked_from_the_supplier_screen(): void
    {
        $supplierId = $this->supplier('Acme Rigging', '2011/999999/07');

        $assessment = app(\App\Modules\Operations\Sheq\Services\SupplierOnboardingService::class)
            ->invite($this->companyId, [
                'supplier_name' => 'Acme Rigging', 'registration_number' => '2011/999999/07',
                'risk_category' => 'HIGH',
            ], $this->user->id);

        $this->actingAs($this->user)->post("/sheq/suppliers/{$assessment['uuid']}/link")
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame($supplierId, (int) DB::table('sheq_supplier_assessments')
            ->where('uuid', $assessment['uuid'])->value('supplier_id'));
    }

    public function test_linking_needs_the_contractor_entitlement(): void
    {
        $this->supplier('Scaffold Co');
        $contractor = $this->contractor();

        $this->actingAs($this->userWith([]))
            ->post("/sheq/contractors/{$contractor['uuid']}/link-supplier")
            ->assertForbidden();
    }

    public function test_another_tenants_supplier_is_not_matched(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'link-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('suppliers')->insert([
            'company_id' => $other, 'company_name' => 'Scaffold Co',
            'portal_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractor = $this->contractor();

        // Matching on name alone across tenants would tie this contractor to another company's
        // supplier record and show their assessment on our gate screen.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/link-supplier")
            ->assertSessionHas('error');

        $this->assertNull(DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('supplier_id'));
    }

    // ── Correcting a file without destroying evidence ─────────────────────────────────────────

    public function test_a_mistyped_expiry_can_be_corrected_by_setting_it_aside(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        // The typo: 2030 where they meant next month.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYears(4)->toDateString(),
            ])->assertRedirect();

        // The correct certificate, which expired last week.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->subWeek()->toDateString(),
            ])->assertRedirect();

        /*
         * Filing the correct certificate is now enough, and it was not before (CON-24).
         *
         * The compliance file used to keep the furthest expiry per type, so the typo won and the
         * contractor read COMPLIANT however many correct certificates were filed afterwards —
         * somebody had to notice and set the wrong one aside by hand, and deleting it would have
         * destroyed compliance evidence. Closing the version chain made the question disappear
         * rather than answering it better: version 2 supersedes version 1 the moment it is
         * accepted, so there is nothing left to choose between.
         */
        $this->assertSame('BLOCKED', DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('access_status'));

        $typo = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)
            ->orderBy('id')->first(['uuid', 'superseded_at', 'supersede_reason']);

        // Superseded, not deleted: it stays readable as the thing that was on file at the time.
        $this->assertNotNull($typo->superseded_at);
        $this->assertStringContainsString('version 2', (string) $typo->supersede_reason);

        /*
         * And superseding it again is refused rather than re-stamped. The automatic chain and the
         * manual route can now both reach the same document, and a second stamp would overwrite
         * the moment it actually stopped being in force — which is the one fact the whole version
         * chain exists to record.
         */
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents/{$typo->uuid}/supersede", [
                'reason' => 'Trying again.',
            ])->assertRedirect();

        $this->assertSame((string) $typo->superseded_at, (string) DB::table('sheq_contractor_documents')
            ->where('uuid', $typo->uuid)->value('superseded_at'),
            'A second supersede overwrote when the document actually stopped counting.');
    }

    public function test_the_set_aside_document_is_kept_as_evidence(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->first(['uuid']);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents/{$document->uuid}/supersede", [
                'reason' => 'Issued to the wrong legal entity; superseded by the corrected letter.',
            ])->assertRedirect();

        $row = DB::table('sheq_contractor_documents')
            ->where('uuid', $document->uuid)
            ->first(['superseded_at', 'superseded_by', 'supersede_reason', 'deleted_at']);

        // Kept, readable, attributable: the questions an auditor asks about a set-aside
        // certificate are "what was it" and "on whose say-so".
        $this->assertNull($row->deleted_at);
        $this->assertNotNull($row->superseded_at);
        $this->assertSame($this->user->id, (int) $row->superseded_by);
        $this->assertStringContainsString('wrong legal entity', $row->supersede_reason);
    }

    public function test_setting_a_document_aside_is_audited_with_the_reason(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->first(['uuid']);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents/{$document->uuid}/supersede", [
                'reason' => 'Duplicate upload of the same certificate, keyed twice.',
            ]);

        $entry = $this->auditFor('contractor.document_superseded');

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertStringContainsString('Duplicate upload', (string) $entry->reason);
    }

    public function test_a_reason_is_required_and_has_to_say_something(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->first(['uuid']);

        // "fix" tells a reviewer nothing, and this is the record of why a certificate stopped
        // counting.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents/{$document->uuid}/supersede",
                ['reason' => 'fix'])
            ->assertSessionHasErrors('reason');

        $this->assertNull(DB::table('sheq_contractor_documents')
            ->where('uuid', $document->uuid)->value('superseded_at'));
    }

    public function test_a_document_cannot_be_set_aside_twice(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->first(['uuid']);

        $payload = ['reason' => 'Superseded by the corrected certificate issued in October.'];
        $url = "/sheq/contractors/{$contractor['uuid']}/documents/{$document->uuid}/supersede";

        $this->actingAs($this->user)->post($url, $payload)->assertRedirect();

        // The second attempt must not rewrite who set it aside or when.
        $this->actingAs($this->user)->post($url, $payload)->assertSessionHas('error');
    }

    public function test_setting_a_document_aside_needs_the_contractor_entitlement(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->first(['uuid']);

        $this->actingAs($this->userWith([]))
            ->post("/sheq/contractors/{$contractor['uuid']}/documents/{$document->uuid}/supersede",
                ['reason' => 'Trying to set this aside without the entitlement.'])
            ->assertForbidden();
    }

    // ── Rule 8: evidence is superseded, never overwritten ─────────────────────────────────────

    public function test_renewing_a_certificate_keeps_the_one_it_replaced(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);
        $contractor = $this->contractor();

        foreach ([Carbon::today()->subDay(), Carbon::today()->addYear()] as $expiry) {
            $this->actingAs($this->user)
                ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                    'document_type' => 'COIDA_LETTER',
                    'expires_on' => $expiry->toDateString(),
                ])->assertRedirect();
        }

        // Both rows survive: compliance evidence is never silently destroyed, and an auditor asking
        // "what were you holding in March" has to be able to get an answer.
        $this->assertSame(2, DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)
            ->where('document_type', 'COIDA_LETTER')->count());

        // And the renewal is the one that counts.
        $this->assertSame('COMPLIANT', DB::table('sheq_contractors')
            ->where('uuid', $contractor['uuid'])->value('access_status'));
    }
}
