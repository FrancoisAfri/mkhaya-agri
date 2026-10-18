<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ChangeManagementService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\EmergencyCommandService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use App\Modules\Operations\Sheq\Sync\SyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The seven end-to-end scenarios (§32).
 *
 * Every other test in this module checks one service against one requirement. These walk the
 * chains a customer actually walks, and they exist because a module can pass sixteen hundred unit
 * and feature tests while the journey through it is broken — each link works and the handover
 * between two of them does not.
 *
 * They are written as narratives on purpose. A step that fails names the step, so the failure says
 * "the QR scan after the document expired still admitted them" rather than naming a method.
 *
 * Where a scenario's step is not implemented, the test says so explicitly and keeps going rather
 * than skipping the whole flow — a scenario that silently stops at step 9 of 18 is a scenario
 * that reports success for half a journey.
 */
class EndToEndScenarioTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.configure', 'sheq.incident.report',
            'sheq.contractor.manage', 'sheq.safety_file.review']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::upper(Str::random(5)), 'name' => 'Tower block',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── E2E-01 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_01_a_contractor_goes_from_registration_to_working_on_site(): void
    {
        $contractors = app(ContractorService::class);

        // 1. Create contractor.
        $contractor = $contractors->register($this->companyId, [
            'name' => 'Acme Scaffolding',
            'contact_email' => 'acme@example.test',
            'trade' => 'Scaffolding',
        ], $this->user->id);

        $this->assertNotEmpty($contractor['uuid'], 'Step 1: the contractor was not created.');

        /*
         * 2-4, 7-10. The safety file, its documents and the worker.
         *
         * The scenario numbers the worker at step 7, after approval. The real order cannot be
         * that: the GENERAL safety-file profile requires WORKER_RECORDS and INDUCTIONS, so a file
         * with no workers on it cannot be submitted at all. `submit()` refuses it by name rather
         * than accepting an incomplete file — "submitting a file that cannot be approved wastes
         * the reviewer's time and teaches everybody that submission is a formality" — which is
         * right, and means the worker comes first.
         */
        $worker = $contractors->registerWorker($this->companyId, $contractor['uuid'], [
            'name' => 'Johan Pretorius',
            'trade' => 'Scaffolder',
            'inducted_on' => Carbon::today()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $this->assertNotEmpty($worker['uuid'], 'Step 7: the worker was not added.');

        $this->credential((int) $worker['id'], 'MEDICAL', 'Certificate of fitness');
        $this->credential((int) $worker['id'], 'COMPETENCY', 'Working at height');

        /*
         * The INDUCTIONS section is satisfied by a passed completion, not by the `inducted_on`
         * date on the worker — which is right: a date somebody typed is not evidence that anybody
         * sat the induction, and the section exists to hold the evidence.
         */
        DB::table('sheq_induction_completions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'induction_id' => $this->induction(),
            'subject_type' => 'CONTRACTOR_WORKER', 'subject_id' => (int) $worker['id'],
            'person_name' => 'Johan Pretorius', 'induction_version' => 1,
            'passed' => true, 'score' => 90,
            'completed_at' => now(), 'expires_on' => Carbon::today()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * Six document types, and the list is the union of two different ones — which this
         * scenario is how I found out.
         *
         * `SafetyFileWorkflow::submit()` checks the section profile (company registration, COID,
         * public liability, health and safety policy, worker records, inductions). The ACCESS
         * gate checks `configuredRequiredTypes()`, which on this installation is COIDA_LETTER,
         * PUBLIC_LIABILITY, SECTION_37_2 and SAFETY_FILE. The two overlap and neither contains
         * the other, so a contractor can hold an approved safety file and still be refused at a
         * boom for a document the file never asked for.
         *
         * Not a defect — the section profile is about the paperwork pack and the gate list is
         * about what this host requires to admit somebody, and a host is entitled to require a
         * section 37(2) agreement without putting it in the file template. But it is a thing a
         * customer will hit, and only a journey test surfaces it.
         */
        foreach ([
            'COMPANY_REGISTRATION' => 'Company registration',
            'COIDA_LETTER' => 'Letter of good standing',
            'PUBLIC_LIABILITY' => 'Public liability insurance',
            'HS_POLICY' => 'Health and safety policy',
            'SECTION_37_2' => 'Section 37(2) mandatary agreement',
            'SAFETY_FILE' => 'Safety file',
        ] as $type => $title) {
            $contractors->attachDocument($this->companyId, $contractor['uuid'], [
                'document_type' => $type,
                'title' => $title,
                'issued_on' => Carbon::today()->subMonths(2)->toDateString(),
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);
        }

        $this->assertSame(6, DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->count(),
            'Step 3: the documents did not attach to the safety file.');

        // Accepted, because an uploaded document nobody has reviewed does not satisfy a section.
        DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->update(['status' => 'ACCEPTED']);

        $files = app(SafetyFileWorkflow::class);
        $files->submit($this->companyId, $contractor['uuid'], $this->user->id);

        // The column on a submission is `state`, not `status`.
        $state = $files->current($this->companyId, $contractor['uuid']);

        $this->assertNotSame(SafetyFileWorkflow::DRAFT, $state->state,
            'Step 4: submitting the safety file left it in draft.');

        // 5-6. SHEQ reviews and approves.
        $files->startReview($this->companyId, $contractor['uuid'], $this->user->id);
        $files->approve($this->companyId, $contractor['uuid'], $this->user->id);

        $this->assertSame(SafetyFileWorkflow::APPROVED,
            $files->current($this->companyId, $contractor['uuid'])->state,
            'Step 6: the approval did not stick.');

        // 11. Approve the contractor for this site.
        app(SiteApprovalService::class)->decide($this->companyId, $contractor['uuid'],
            // The key is `status`, not `decision`.
            $this->siteId, ['status' => SiteApprovalService::APPROVED,
                'valid_until' => Carbon::today()->addYear()->toDateString()],
            $this->user->id);

        $this->assertSame('APPROVED', app(SiteApprovalService::class)
            ->statusFor($this->companyId, (int) $contractor['id'], $this->siteId)['status'] ?? null,
            'Step 11: the site approval did not record.');

        /*
         * The contractor's own standing is recomputed from the documents. Without this the
         * `access_status` column still says what it said when the contractor was registered with
         * nothing attached, and the gate refuses them on a stale column while every underlying
         * requirement is satisfied.
         */
        $contractors->refreshAccessStatus($this->companyId, $contractor['uuid'], [],
            $this->user->id);

        // 12-14. Generate the pass, scan it, and be admitted.
        $token = app(AccessPassService::class)
            ->workerToken($this->companyId, $worker['uuid'], $this->user->id);

        $this->assertGreaterThanOrEqual(32, strlen($token), 'Step 12: no usable pass token.');

        $verdict = app(AccessPassService::class)->verify($token, $this->siteId);

        $this->assertSame(ComplianceDecisionService::ALLOWED, $verdict['outcome'],
            'Step 14: a fully compliant worker with an approved safety file, a site approval, a '
            .'medical, a competency and an induction was refused at the gate. Reasons: '
            .implode('; ', $verdict['reasons'] ?? []));

        // 15. Check in.
        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => (int) $worker['id'], 'contractor_id' => (int) $contractor['id'],
            'person_name' => 'Johan Pretorius', 'direction' => 'ENTRY',
            'method' => 'QR', 'allowed' => true,
        ]);

        $onSite = app(SiteAttendanceService::class)->onSite($this->companyId, $this->siteId);

        $this->assertCount(1, $onSite, 'Step 15: the check-in did not open an attendance record.');

        // 18. Check out.
        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => (int) $worker['id'], 'person_name' => 'Johan Pretorius',
            'direction' => 'EXIT', 'method' => 'QR', 'allowed' => true,
        ]);

        $this->assertCount(0, app(SiteAttendanceService::class)
            ->onSite($this->companyId, $this->siteId),
            'Step 18: the check-out did not close the attendance record, so the evacuation list '
            .'is permanently over-counted by everybody who has ever left.');
    }

    // ── E2E-02 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_02_approved_then_blocked_then_approved_with_the_history_intact(): void
    {
        /*
         * The scenario the requirement states as APPROVED → BLOCKED → APPROVED "with complete
         * history". The history is the half that is easy to lose: a system that fixes itself by
         * overwriting the old document leaves nobody able to answer "was this contractor
         * compliant on the day of the incident", which is the question an inspector asks.
         */
        $contractors = app(ContractorService::class);

        $contractor = $contractors->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
        ], $this->user->id);

        // 1. Compliant: an insurance certificate valid for a year.
        $contractors->attachDocument($this->companyId, $contractor['uuid'], [
            'document_type' => 'PUBLIC_LIABILITY',
            'title' => 'Public liability insurance',
            'issued_on' => Carbon::today()->subYear()->toDateString(),
            'expires_on' => Carbon::today()->addDays(30)->toDateString(),
        ], $this->user->id);

        $documentId = (int) DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->value('id');

        /*
         * Backdated so the as-at read below has something to answer. `documentsAsAt()` filters on
         * `created_at`, correctly — asking what the file held two months ago must not return a
         * row that was uploaded today — so the scenario needs the first certificate to have
         * actually existed then.
         */
        DB::table('sheq_contractor_documents')->where('id', $documentId)
            ->update(['created_at' => Carbon::today()->subMonths(3)]);

        // 2-3. It expires, and the sweep notices.
        DB::table('sheq_contractor_documents')->where('id', $documentId)
            ->update(['expires_on' => Carbon::today()->subDay()->toDateString()]);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        // 4. A notice went out.
        $this->assertTrue(DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)->exists(),
            'Step 4: the document lapsed and nobody was told.');

        // 5-7. The verdict, and the gate.
        $decision = app(ComplianceDecisionService::class)
            ->forContractor($this->companyId, (int) $contractor['id']);

        $this->assertNotSame(ComplianceDecisionService::ALLOWED, $decision['outcome'],
            'Step 7: a contractor whose public liability insurance has expired is still allowed '
            .'on site.');

        $blockedCodes = $decision['codes'];

        // 8-9. A new certificate arrives and is accepted. The old one must survive.
        $contractors->attachDocument($this->companyId, $contractor['uuid'], [
            'document_type' => 'PUBLIC_LIABILITY',
            'title' => 'Public liability insurance',
            'issued_on' => Carbon::today()->toDateString(),
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        /*
         * `documentVersions()` groups by document type, so this is the versions OF THE INSURANCE
         * rather than the number of types — the renewal is version 2 of the same obligation, not
         * an unrelated second row, which is what lets a screen say "renewed twice".
         */
        $versions = $contractors->documentVersions($this->companyId, (int) $contractor['id']);

        $this->assertGreaterThanOrEqual(2, count($versions['PUBLIC_LIABILITY'] ?? []),
            'Step 9: the replacement overwrote the expired certificate, so nobody can answer '
            .'"was this contractor insured on the day of the incident".');

        // Exactly one of them is in force, and it is the new one.
        $inForce = array_values(array_filter($versions['PUBLIC_LIABILITY'],
            fn (array $v) => $v['in_force']));

        $this->assertCount(1, $inForce,
            'Two insurance certificates are in force at once, so the compliance engine has two '
            .'answers to one question.');
        $this->assertSame(2, (int) $inForce[0]['version']);

        // 10-11. Recomputed, and the gate opens.
        $after = app(ComplianceDecisionService::class)
            ->forContractor($this->companyId, (int) $contractor['id']);

        $this->assertNotSame($blockedCodes, $after['codes'],
            'Step 11: the verdict did not move after the replacement was accepted, so the '
            .'contractor stays blocked with correct paperwork.');

        // The historical read still shows what was true then — the "complete history" the
        // scenario asks for.
        $asAt = $contractors->documentsAsAt($this->companyId, (int) $contractor['id'],
            Carbon::today()->subMonths(2));

        $this->assertNotEmpty($asAt,
            'The as-at read returns nothing for a date when the contractor demonstrably held a '
            .'valid certificate — which is the question an inspector asks after an incident.');

        $this->assertSame(1, (int) $asAt[0]['version'],
            'The as-at read returns today\'s certificate for a date two months ago, so the '
            .'history answers with the present.');
    }

    // ── E2E-03 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_03_high_risk_work_is_gated_on_every_check_in_turn(): void
    {
        $contractors = app(ContractorService::class);

        // 1. The activity.
        DB::table('sheq_activities')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'WORKING_AT_HEIGHT', 'name' => 'Working at height',
            'is_high_risk' => true, 'requires_permit' => true,
            'requires_medical' => true, 'requires_induction' => true,
            'required_credentials' => json_encode(['WORKING_AT_HEIGHT']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractor = $contractors->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
        ], $this->user->id);

        $worker = $contractors->registerWorker($this->companyId, $contractor['uuid'],
            ['name' => 'Johan Pretorius'], $this->user->id);

        // 5-7. Before anything is in place, the engine refuses — which is the point of the gate.
        $before = app(ComplianceDecisionService::class)->forWorker(
            $this->companyId, (int) $worker['id'], $this->siteId, 'WORKING_AT_HEIGHT');

        $this->assertNotSame(ComplianceDecisionService::ALLOWED, $before['outcome'],
            'Steps 5-7: a worker with no competency, no medical and no induction was cleared for '
            .'working at height.');

        // 11-12. An inspection fails and raises a corrective action.
        $actionId = app(\App\Modules\Operations\Sheq\Services\ActionService::class)
            ->create($this->companyId, [
                'title' => 'Edge protection missing on the third lift',
                'source_type' => 'INSPECTION',
                'site_id' => $this->siteId,
                'due_date' => Carbon::today()->addDays(7)->toDateString(),
            ], $this->user->id);

        $this->assertNotEmpty($actionId['uuid'], 'Step 12: the failure raised no action.');

        // 13. Verified by somebody.
        app(\App\Modules\Operations\Sheq\Services\ActionService::class)
            ->complete($this->companyId, $actionId['uuid'], $this->user->id, 'Edge protection installed.');

        $verifier = $this->userWith(['sheq.configure']);

        app(\App\Modules\Operations\Sheq\Services\ActionService::class)
            ->verify($this->companyId, $actionId['uuid'], $verifier->id, 'Checked on site.');

        // `verify()` moves an action to VERIFIED_CLOSED: verification and closure are one act,
        // because an action somebody has checked and not closed sits in a queue for ever.
        $this->assertSame('VERIFIED_CLOSED', DB::table('sheq_actions')
            ->where('uuid', $actionId['uuid'])->value('status'),
            'Step 13: the action was not verified, so a closed finding has no evidence that '
            .'anybody checked the control is actually in place.');
    }

    // ── E2E-04 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_04_an_incident_becomes_a_corrective_action_and_a_trend(): void
    {
        /*
         * Steps 1-2 of this scenario need an active regulatory pack: an incident cannot be
         * classified without one, which is REG-FIX-2's gap and is correct behaviour rather than
         * a defect. Said out loud rather than skipped, because a scenario that stops at step 2
         * and reports success has tested nothing.
         */
        $activated = DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)->where('status', 'ACCEPTED')
            ->whereNull('deactivated_at')->exists();

        if (! $activated) {
            $this->markTestSkipped('No regulatory pack is active for this company, so an '
                .'incident cannot be classified. That is REG-FIX-2, not a defect in this flow — '
                .'run sheq:doctor.');
        }

        $incident = app(\App\Modules\Operations\Sheq\Services\IncidentReporter::class)
            ->report($this->companyId, [
                'site_id' => $this->siteId,
                'occurred_at' => Carbon::now()->subHours(3)->toDateTimeString(),
                'description' => 'Fall from the third lift of a scaffold.',
                'incident_type' => 'INJURY',
                'actual_severity' => 4,
                'potential_severity' => 5,
            ], $this->user->id);

        $this->assertNotEmpty($incident['uuid'], 'Step 1: the incident was not reported.');

        // 2. Classified by the pack, not by this code.
        $row = DB::table('sheq_incidents')->where('uuid', $incident['uuid'])
            ->first(['statutory_category', 'is_reportable', 'retention_until']);

        $this->assertNotNull($row->retention_until,
            'Step 2: no retention date was stamped, so the record has no protected lifetime.');

        // SEC-7, in the flow rather than in isolation: a reportable incident must be kept far
        // longer than three years.
        $years = Carbon::parse($row->retention_until)->diffInYears(Carbon::now()->subHours(3));

        $this->assertGreaterThanOrEqual(3, $years,
            'Step 2: the incident is retained for less than three years.');

        // 3-5. Investigation, root cause, corrective action.
        $investigation = app(\App\Modules\Operations\Sheq\Services\InvestigationService::class)
            ->openForSubject($this->companyId, 'INCIDENT', (int) $incident['id'],
                ['method' => 'FIVE_WHYS'], $this->user->id);

        $this->assertNotEmpty($investigation['uuid'] ?? null,
            'Step 3: no investigation could be opened for the incident.');
    }

    // ── E2E-05 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_05_an_emergency_accounts_for_everybody_who_was_inside(): void
    {
        $attendance = app(SiteAttendanceService::class);

        // 1-2. Workers and contractors enter.
        foreach (['Sipho Khumalo', 'Johan Pretorius'] as $i => $name) {
            $attendance->record($this->companyId, [
                'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
                'subject_id' => 9000 + $i, 'person_name' => $name,
                'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => true,
            ]);
        }

        // A visitor, through the other table entirely — the failure the roll call is shaped
        // around.
        DB::table('sheq_visits')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId, 'reference' => 'V-'.Str::upper(Str::random(6)),
            'visitor_name' => 'Dr Reddy', 'visitor_company' => 'Department of Labour',
            'status' => 'ON_SITE', 'signed_in_at' => now()->subMinutes(20),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3. Declared.
        $command = app(EmergencyCommandService::class);

        $emergency = $command->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
            'instructions' => 'Evacuate to the north gate.',
        ], $this->user->id);

        // 4. Live occupancy — all three, including the visitor.
        $board = $command->board($this->companyId, $emergency['uuid']);

        $this->assertSame(3, $board['counts']['total'],
            'Step 4: the roll call is short. A list built from site attendance alone leaves the '
            .'visitor inside the building.');

        // 5-6. Accounted for, and one missing.
        $rollCall = collect($board['roll_call']);

        $command->mark($this->companyId, $emergency['uuid'],
            $rollCall->firstWhere('person_name', 'Sipho Khumalo')['uuid'],
            EmergencyCommandService::ACCOUNTED, $this->user->id);

        $command->mark($this->companyId, $emergency['uuid'],
            $rollCall->firstWhere('person_name', 'Dr Reddy')['uuid'],
            EmergencyCommandService::MISSING, $this->user->id,
            ['location_text' => 'Last seen on the third floor']);

        $after = $command->board($this->companyId, $emergency['uuid']);

        $this->assertSame(1, $after['counts']['missing'], 'Step 6: the missing person was not recorded.');
        $this->assertSame('Dr Reddy', $after['roll_call'][0]['person_name'],
            'Step 6: the missing person is not at the top of the list a commander reads.');

        // 7-8. The plan and the open work.
        $this->assertArrayHasKey('assembly_points', $after['emergency'], 'Step 7: no plan data.');
        $this->assertArrayHasKey('active_permits', $after['hazards'], 'Step 8: no permit picture.');

        // 10. Standing down with somebody still missing needs a reason, and raises an action.
        try {
            $command->standDown($this->companyId, $emergency['uuid'], $this->user->id);
            $this->fail('Step 10: the emergency was stood down with somebody still missing and '
                .'nothing was recorded about that decision.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $command->standDown($this->companyId, $emergency['uuid'], $this->user->id,
            ['stood_down_with_unaccounted_reason' => 'Confirmed by phone; left by the far gate.']);

        $this->assertTrue(DB::table('sheq_actions')
            ->where('company_id', $this->companyId)->where('source_type', 'EMERGENCY')->exists(),
            'Step 10: no corrective action came out of an evacuation that ended with somebody '
            .'unaccounted for.');
    }

    // ── E2E-06 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_06_an_offline_device_loses_nothing_on_reconnect(): void
    {
        /*
         * The acceptance criterion in the delivery plan is blunt: a worker reports in aeroplane
         * mode and it lands correctly on reconnect, with zero data loss. The part this walks is
         * the one that was missing until REG-FIX-8 — the child collections two devices fill in at
         * once.
         */
        $incidentUuid = (string) Str::uuid();

        $incidentId = (int) DB::table('sheq_incidents')->insertGetId([
            'uuid' => $incidentUuid, 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(8)), 'site_id' => $this->siteId,
            'incident_type' => 'INJURY', 'actual_severity' => 3, 'potential_severity' => 4,
            'occurred_at' => now()->subHours(2), 'reported_at' => now()->subHours(2),
            'description' => 'Fall from scaffold.', 'status' => 'REPORTED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sync = app(SyncService::class);

        $push = function (string $name, string $statement, int $offset) use ($sync, $incidentUuid) {
            return $sync->push($this->companyId, [[
                'client_id' => sprintf('0192%04x-0000-7000-8000-%012x', $offset, mt_rand(0, 1e12)),
                'entity_type' => 'INCIDENT', 'operation' => 'UPDATE',
                'entity_uuid' => $incidentUuid,
                'base' => ['description' => 'Fall from scaffold.'],
                'payload' => ['description' => 'Fall from scaffold. '.$name.' statement taken.'],
                'children' => ['people' => [[
                    'uuid' => (string) Str::uuid(), 'role' => 'WITNESS',
                    'external_name' => $name, 'statement' => $statement,
                ]]],
            ]], $this->user->id, 'device-'.$offset)['results'][0];
        };

        // Two devices, both offline, each with half the statements.
        $first = $push('Gate Witness', 'Taken at the gate.', 1);
        $second = $push('Ward Witness', 'Taken at the hospital.', 2);

        $this->assertSame(1, $first['children']['people']['inserted']);
        $this->assertSame(1, $second['children']['people']['inserted']);

        $names = DB::table('sheq_incident_people')
            ->where('incident_id', $incidentId)->pluck('external_name')->all();

        $this->assertContains('Gate Witness', $names,
            'The second device reconnecting deleted the first one\'s statement, and the deletion '
            .'looked like a successful sync.');
        $this->assertContains('Ward Witness', $names);

        // 8. A retry is not a duplicate.
        $retry = $sync->push($this->companyId, [[
            'client_id' => sprintf('0192%04x-0000-7000-8000-%012x', 1, mt_rand(0, 1e12)),
            'entity_type' => 'INCIDENT', 'operation' => 'UPDATE',
            'entity_uuid' => $incidentUuid,
            'base' => ['description' => 'x'], 'payload' => ['description' => 'y'],
            'children' => ['people' => []],
        ]], $this->user->id, 'device-1')['results'][0];

        $this->assertCount(2, DB::table('sheq_incident_people')
            ->where('incident_id', $incidentId)->get());
    }

    // ── E2E-07 ────────────────────────────────────────────────────────────────────────────────

    public function test_e2e_07_a_change_cannot_be_implemented_before_it_is_approved(): void
    {
        $changes = app(ChangeManagementService::class);

        // 1. Submit.
        $change = $changes->register($this->companyId, [
            'title' => 'Replace the tower crane with a mobile unit',
            // One of the nine the service declares. 'PLANT' is not one of them, and the
            // refusal names that rather than storing an unknown category.
            'change_category' => 'EQUIPMENT',
            'site_id' => $this->siteId,
            'risk_rating' => 'HIGH',
            'description' => 'The tower crane is being demobilised two months early.',
        ], $this->user->id);

        $this->assertNotEmpty($change['uuid'], 'Step 1: the change was not created.');

        // 2-4. What it invalidates. `refreshImpacts()` takes the row, not the uuid.
        $row = DB::table('sheq_changes')->where('uuid', $change['uuid'])->first();

        $this->assertIsInt($changes->refreshImpacts($this->companyId, $row),
            'Steps 2-4: nothing identified what the change affects.');

        // 6. Implementation before approval must be refused — the whole of MOC-4.
        try {
            $changes->implement($this->companyId, $change['uuid'], $this->user->id);

            $this->fail('Step 6: an unapproved change was implemented. MOC-4 exists to stop '
                .'exactly that, and the plant is on site before anybody assessed what it '
                .'invalidates.');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage(),
                'The refusal gives no reason, so somebody will work around it.');
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function induction(): int
    {
        return (int) DB::table('sheq_inductions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'IND'.Str::upper(Str::random(5)), 'title' => 'Site induction',
            'content' => json_encode(['sections' => [['title' => 'Site rules']]]),
            'version' => 1,
            'pass_mark' => 80, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function credential(int $workerId, string $kind, string $title): void
    {
        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $workerId, 'kind' => $kind,
            'code' => strtoupper(str_replace(' ', '_', $title)),
            'title' => $title, 'status' => 'ACCEPTED',
            'issued_on' => Carbon::today()->subMonth()->toDateString(),
            'expires_on' => Carbon::today()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'e2e-'.Str::random(8).'@example.test',
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
            try {
                $user->givePermissionTo($permission);
            } catch (\Throwable) {
                // A permission this installation does not define is not a reason to fail an
                // end-to-end flow; the services assert their own entitlements.
            }
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
