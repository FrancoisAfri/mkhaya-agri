<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The calculated contractor status, and what refuses entry (CON-9, CON-10).
 *
 * Two distinctions the whole design turns on:
 *
 * **NON_COMPLIANT is not BLOCKED.** Something required is missing, and whether that refuses entry
 * is the host's decision — a mine blocks on an expired medical, an office park records it and lets
 * the consultant in. Collapsing them means either every omission stops work or none does.
 *
 * **PENDING_APPROVAL is not COMPLIANT.** Every document can be in place while nobody has accepted
 * the file. Calling that compliant would make the review workflow decorative.
 */
class ContractorComplianceStatusTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

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

        $this->requireDocuments(['COIDA_LETTER']);

        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);

        /*
         * The safety file's own sections are not what is under test here — the status computation
         * is. Left demanded, every submission would be refused for want of twenty-five documents
         * and none of these tests would reach the thing they are about.
         */
        app(\App\Modules\Operations\Sheq\Services\SafetyFileService::class)
            ->applyProfile($this->companyId, 'GENERAL', $this->user->id);
        DB::table('sheq_safety_file_sections')->where('company_id', $this->companyId)
            ->update(['is_required' => false]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ccs-'.Str::random(8).'@example.test',
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

    private function requireDocuments(array $types): void
    {
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode($types), 'updated_at' => now()],
        );
    }

    private function rules(array $rules): void
    {
        app(ContractorComplianceStatus::class)
            ->saveRules($this->companyId, $rules, $this->user->id);
    }

    private function fileDocument(array $attributes = []): void
    {
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], array_merge([
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $attributes), $this->user->id);
    }

    private function accessStatus(): string
    {
        return (string) DB::table('sheq_contractors')
            ->where('uuid', $this->contractor['uuid'])->value('access_status');
    }

    private function mayWork(?int $siteId = null, ?string $activity = null): array
    {
        return app(ContractorService::class)->mayWork($this->companyId,
            $this->contractor['uuid'], [], $siteId, $activity);
    }

    private function worker(string $name = 'T. Mokoena', array $overrides = []): int
    {
        return (int) DB::table('sheq_contractor_workers')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => $name,
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function credential(int $workerId, array $overrides = []): void
    {
        DB::table('sheq_contractor_worker_credentials')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $workerId, 'kind' => 'MEDICAL', 'title' => 'Certificate of fitness',
            'expires_on' => Carbon::today()->subMonth()->toDateString(),
            'status' => 'ACCEPTED', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    // ── CON-9: the status is calculated ───────────────────────────────────────────────────────

    public function test_one_expired_mandatory_document_immediately_changes_the_status(): void
    {
        $this->fileDocument();
        $this->assertSame(ContractorComplianceStatus::COMPLIANT, $this->accessStatus());

        // The certificate lapses where it sits. Nobody does anything; time does.
        DB::table('sheq_contractor_documents')->where('company_id', $this->companyId)
            ->update(['expires_on' => Carbon::today()->subDay()->toDateString()]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());
    }

    public function test_a_file_waiting_for_review_is_pending_not_compliant(): void
    {
        $this->fileDocument();
        $this->assertSame(ContractorComplianceStatus::COMPLIANT, $this->accessStatus());

        app(SafetyFileWorkflow::class)->submit($this->companyId, $this->contractor['uuid'],
            $this->user->id);

        /*
         * Every document is in place and nobody has accepted the file. Reporting that as compliant
         * would make the whole review workflow decorative.
         */
        $this->assertSame(ContractorComplianceStatus::PENDING_APPROVAL, $this->accessStatus());
    }

    public function test_approving_the_file_makes_them_compliant(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id);

        $this->assertSame(ContractorComplianceStatus::COMPLIANT, $this->accessStatus());
    }

    public function test_an_approval_that_lapsed_over_the_weekend_does_not_still_read_approved(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id,
            Carbon::yesterday()->toDateString());

        /*
         * The nightly pass that records APPROVED → EXPIRED runs on weekday mornings, so an
         * approval whose last day was Friday is still stored as APPROVED all weekend. A gate on a
         * site that works Saturdays would have admitted them on an approval that had run out. The
         * date decides, and the command's job is to write the transition down, not to cause it.
         */
        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());

        $verdict = $this->mayWork();
        $this->assertSame(SafetyFileWorkflow::EXPIRED, $verdict['safety_file_state']);

        // And the refusal names the date, not the state: "expired" at a boom starts an argument,
        // "the approval stood until 7 October" ends one.
        $this->assertStringContainsString('stood until', implode(' ', $verdict['reasons']));
    }

    public function test_an_expired_approval_is_not_compliant_even_with_every_document_on_file(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id,
            Carbon::today()->subMonth()->toDateString());
        $workflow->expireLapsed($this->companyId);

        /*
         * This is the state that looked compliant from the inside. Nothing was missing — every
         * document was on file, in date and accepted — so no document rule fired, and the file's
         * own state was never consulted. The status came out COMPLIANT on a contractor whose
         * approval had run out a month earlier.
         */
        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());
        $this->assertFalse($this->mayWork()['allowed']);
    }

    public function test_a_file_sent_back_for_changes_does_not_read_as_approved(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->requestChanges($this->companyId, $this->contractor['uuid'],
            'The COID letter is for the wrong entity.', [], $this->user->id);

        // Sent back is the host declining the file. Reading it as approved would make it compliant
        // for exactly as long as the corrections stayed outstanding.
        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());
        $this->assertFalse($this->mayWork()['allowed']);
    }

    public function test_an_approval_that_runs_out_today_is_still_good_today(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id,
            Carbon::today()->toDateString());

        // "Valid until the 8th" includes the 8th; cutting it off at midnight the night before
        // would refuse people on a day their paperwork plainly covers.
        $this->assertSame(ContractorComplianceStatus::COMPLIANT, $this->accessStatus());
    }

    public function test_a_suspended_file_outranks_everything_else(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->suspend($this->companyId, $this->contractor['uuid'],
            'Two unreported incidents on site last week.', $this->user->id);

        // An explicit refusal outranks a computed one: somebody decided this, and a computed
        // status that overrode a human decision would be the wrong way round.
        $this->assertSame(ContractorComplianceStatus::SUSPENDED, $this->accessStatus());
        $this->assertFalse($this->mayWork()['allowed']);
    }

    public function test_a_suspension_names_the_reason_it_was_suspended(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->suspend($this->companyId, $this->contractor['uuid'],
            'Two unreported incidents on site last week.', $this->user->id);

        // "Access denied" at a boom with a queue behind it starts an argument.
        $this->assertStringContainsString('unreported incidents',
            implode(' ', $this->mayWork()['reasons']));
    }

    public function test_a_document_expiring_soon_reads_as_expiring(): void
    {
        $this->fileDocument(['expires_on' => Carbon::today()->addDays(10)->toDateString()]);

        $this->assertSame(ContractorComplianceStatus::EXPIRING, $this->accessStatus());
    }

    // ── CON-10: blocking is configurable, and says exactly why ────────────────────────────────

    public function test_the_reason_is_exact_rather_than_a_code(): void
    {
        // Nothing filed at all.
        $reasons = $this->mayWork()['reasons'];

        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('not been supplied', implode(' ', $reasons));
    }

    public function test_switching_a_rule_off_records_the_fault_without_refusing_entry(): void
    {
        $this->rules(['MISSING_MANDATORY_DOCUMENT' => false, 'EXPIRED_COID' => false]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        /*
         * A mine blocks on this; an office park records it and lets the consultant in. And the
         * finding is still reported — switching a rule off must not mean "stop checking", or a
         * host loses the information along with the refusal.
         */
        $this->assertSame(ContractorComplianceStatus::NON_COMPLIANT, $this->accessStatus());

        $verdict = $this->mayWork();
        $this->assertTrue($verdict['allowed']);
        $this->assertNotEmpty($verdict['advisory']);
    }

    public function test_the_shipped_defaults_keep_blocking_on_documents(): void
    {
        /*
         * The rules were not configurable before, and a missing mandatory document has always
         * blocked. An upgrade that admitted contractors who were refused the day before would be
         * the worst possible way to ship a configuration feature.
         */
        $defaults = app(ContractorComplianceStatus::class)->rulesFor($this->companyId);

        $this->assertTrue($defaults['MISSING_MANDATORY_DOCUMENT']);
        $this->assertTrue($defaults['EXPIRED_MANDATORY_DOCUMENT']);
        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());
    }

    public function test_the_worker_rules_ship_off_so_nobody_is_blocked_for_missing_data(): void
    {
        $defaults = app(ContractorComplianceStatus::class)->rulesFor($this->companyId);

        /*
         * On the day this ships no contractor has filed a worker credential, so defaulting these on
         * would block every contractor on the estate for want of data nobody has been asked for.
         */
        $this->assertFalse($defaults['EXPIRED_COMPETENCY']);
        $this->assertFalse($defaults['EXPIRED_MEDICAL']);
        $this->assertFalse($defaults['INCOMPLETE_INDUCTION']);
    }

    public function test_a_rule_the_tenant_has_never_seen_takes_its_shipped_default(): void
    {
        // A stored set written before a rule existed must not leave the new rule undefined.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_blocking_rules' => json_encode(['EXPIRED_COID' => false])]);

        $rules = app(ContractorComplianceStatus::class)->rulesFor($this->companyId);

        $this->assertFalse($rules['EXPIRED_COID']);
        $this->assertTrue($rules['MISSING_MANDATORY_DOCUMENT']);
    }

    public function test_changing_the_rules_is_audited(): void
    {
        $this->rules(['EXPIRED_MEDICAL' => true]);

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.blocking_rules_changed')
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertTrue(json_decode((string) $entry->after, true)['EXPIRED_MEDICAL']);
    }

    public function test_an_unknown_rule_name_is_not_stored(): void
    {
        $saved = app(ContractorComplianceStatus::class)
            ->saveRules($this->companyId, ['NOT_A_RULE' => true], $this->user->id);

        // A typo'd rule would otherwise sit in the configuration for ever, looking like a switch
        // that does nothing.
        $this->assertArrayNotHasKey('NOT_A_RULE', $saved);
    }

    // ── CON-10: the worker conditions ─────────────────────────────────────────────────────────

    public function test_an_expired_worker_medical_blocks_where_the_host_configured_it(): void
    {
        $this->fileDocument();
        $this->rules(['EXPIRED_MEDICAL' => true]);

        $this->credential($this->worker());

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());
    }

    public function test_the_refusal_names_the_worker_and_the_date(): void
    {
        $this->fileDocument();
        $this->rules(['EXPIRED_MEDICAL' => true]);
        $this->credential($this->worker('T. Mokoena'));

        $reasons = implode(' ', $this->mayWork()['reasons']);

        /*
         * "A worker's medical has expired" sends somebody to read a list. "T. Mokoena's
         * certificate of fitness expired on 3 March" is actionable at the boom, which is what
         * CON-10's exact reason is for.
         */
        $this->assertStringContainsString('T. Mokoena', $reasons);
        $this->assertStringContainsString('Certificate of fitness', $reasons);
        $this->assertStringContainsString(Carbon::today()->subMonth()->toDateString(), $reasons);
    }

    public function test_an_unaccepted_credential_is_not_treated_as_expired(): void
    {
        $this->fileDocument();
        $this->rules(['EXPIRED_MEDICAL' => true]);

        $this->credential($this->worker(), ['status' => 'SUPPLIED']);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        // An unaccepted credential is not evidence, so it cannot be the thing that expired — it
        // simply never counted.
        $this->assertSame(ContractorComplianceStatus::COMPLIANT, $this->accessStatus());
    }

    public function test_an_expired_competency_for_other_work_does_not_refuse_this_work(): void
    {
        $this->fileDocument();
        $this->rules(['EXPIRED_COMPETENCY' => true]);

        $this->credential($this->worker(), [
            'kind' => 'COMPETENCY', 'title' => 'Rigging', 'activities' => json_encode(['LIFTING']),
        ]);

        // They may paint but not lift. A single yes-or-no for the whole contractor refuses work
        // that is perfectly in order.
        $this->assertFalse($this->mayWork(null, 'LIFTING')['allowed']);
        $this->assertTrue($this->mayWork(null, 'PAINTING')['allowed']);
    }

    public function test_a_worker_with_no_current_induction_is_named(): void
    {
        $this->fileDocument();
        $this->rules(['INCOMPLETE_INDUCTION' => true]);

        $this->worker('N. Dube', ['inducted_on' => null, 'induction_expires' => null]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());
        $this->assertStringContainsString('N. Dube', implode(' ', $this->mayWork()['reasons']));
    }

    public function test_an_induction_completed_in_this_system_counts(): void
    {
        $this->fileDocument();
        $this->rules(['INCOMPLETE_INDUCTION' => true]);

        $workerId = $this->worker('N. Dube', ['inducted_on' => null, 'induction_expires' => null]);

        $inductions = app(\App\Modules\Operations\Sheq\Services\InductionService::class);

        $induction = $inductions->publish($this->companyId, [
            'code' => 'IND'.Str::random(5), 'title' => 'Site induction', 'audience' => 'CONTRACTOR',
            'content' => [['heading' => 'Assembly point', 'body' => 'Car park A.']],
            'questions' => [['id' => 'q1', 'text' => 'Where?',
                'options' => ['Car park A', 'The canteen'], 'answer' => 'Car park A']],
            'requires_signature' => false,
        ], $this->user->id);

        $inductions->complete($this->companyId, $induction['uuid'], [
            'subject_type' => 'CONTRACTOR_WORKER', 'subject_id' => $workerId,
            'person_name' => 'N. Dube', 'answers' => ['q1' => 'Car park A'],
        ]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        // The access gate honours either source, and a status that disagreed with the gate would
        // be worse than no status.
        $this->assertSame(ContractorComplianceStatus::COMPLIANT, $this->accessStatus());
    }

    // ── The event a gate system consumes ──────────────────────────────────────────────────────

    public function test_the_blocked_event_carries_the_exact_reasons(): void
    {
        $this->fileDocument();

        DB::table('sheq_contractor_documents')->where('company_id', $this->companyId)
            ->update(['expires_on' => Carbon::today()->subDay()->toDateString()]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        $event = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.blocked')
            ->orderByDesc('id')->first();

        $this->assertNotNull($event);

        $payload = json_decode((string) $event->payload, true);

        // A webhook that says "MISSING_MANDATORY_DOCUMENT" tells a gate system nothing it can show
        // the person standing in front of it.
        $this->assertNotEmpty($payload['details']);
        $this->assertStringContainsString('expired', strtolower(implode(' ', $payload['details'])));
    }

    // ── An override is an explicit decision and outranks the computed refusal ─────────────────

    public function test_a_live_override_admits_them_and_keeps_saying_the_file_is_short(): void
    {
        app(ContractorService::class)->overrideAccess($this->companyId,
            $this->contractor['uuid'],
            'Emergency repair; letter of good standing confirmed by phone with the Commissioner.',
            Carbon::today()->addDays(3)->toDateString(), $this->user->id);

        $verdict = $this->mayWork();

        /*
         * A named person accepting the risk of an incomplete file, for a stated period, on the
         * record. The same reasoning that puts a suspension above everything computed puts this
         * above it in the other direction.
         */
        $this->assertTrue($verdict['allowed']);
        $this->assertSame(ContractorComplianceStatus::EXPIRING, $verdict['status']);

        // And the findings are not discarded: the file is still short and the dashboard has to
        // keep saying so for as long as the override lasts.
        $this->assertNotEmpty($verdict['advisory']);
        $this->assertSame([], $verdict['reasons']);
    }

    public function test_a_suspension_beats_an_override(): void
    {
        $this->fileDocument();

        $workflow = app(SafetyFileWorkflow::class);
        $workflow->submit($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->approve($this->companyId, $this->contractor['uuid'], $this->user->id);
        $workflow->suspend($this->companyId, $this->contractor['uuid'],
            'Unreported incident.', $this->user->id);

        app(ContractorService::class)->overrideAccess($this->companyId,
            $this->contractor['uuid'], 'Trying to override a suspension.',
            Carbon::today()->addDays(3)->toDateString(), $this->user->id);

        // Two explicit decisions, and the refusal is the one that stands: an override exists to
        // admit somebody whose paperwork is short, not to overrule a suspension somebody imposed.
        $this->assertSame(ContractorComplianceStatus::SUSPENDED, $this->accessStatus());
        $this->assertFalse($this->mayWork()['allowed']);
    }

    // ── The screen can see the verdict and change the rules ───────────────────────────────────

    public function test_the_screen_shows_the_verdict_and_the_exact_reasons(): void
    {
        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->assertOk()->viewData('page')['props'];

        // The only question a gatehouse is asking, with the answer it can read out.
        $this->assertSame(ContractorComplianceStatus::BLOCKED, $props['verdict']['status']);
        $this->assertFalse($props['verdict']['allowed']);
        $this->assertNotEmpty($props['verdict']['reasons']);
    }

    public function test_the_screen_offers_every_rule_a_host_may_actually_set(): void
    {
        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->viewData('page')['props'];

        /*
         * Two failures, in opposite directions, and both matter.
         *
         * A configurable rule with no switch is a rule nobody can configure. A switch for a rule
         * that is NOT a host's to set is worse: somebody turns it off, believes they have, and
         * the gate goes on refusing — a control that silently does nothing. `SITE_BLOCKED` is
         * the second kind, because a site bans a contractor for reasons head office knows
         * nothing about and a head-office switch must not walk them back through that gate.
         *
         * Asserted against the constants rather than a hand-written list, so a rule added to
         * either set without the other being considered fails here.
         */
        $this->assertSame(ContractorComplianceStatus::CONFIGURABLE_RULES, $props['allRules']);

        foreach (array_diff(array_keys(ContractorComplianceStatus::RULES),
            ContractorComplianceStatus::CONFIGURABLE_RULES) as $fixed) {
            $this->assertNotContains($fixed, $props['allRules'],
                "{$fixed} is not a host's to change, so offering a switch for it is a control "
                .'that does nothing.');
            $this->assertContains($fixed, $props['alwaysBlocks'],
                "{$fixed} is absent from the switches and unexplained, which reads as a rule "
                .'that was forgotten rather than one that is deliberately fixed.');
        }

        // The evaluated set still answers for every rule there is, switchable or not: the gate
        // reads this, and a rule missing from it would be read as false.
        $this->assertCount(count(ContractorComplianceStatus::RULES), $props['blockingRules']);
    }

    public function test_saving_the_rules_from_the_screen_recomputes_the_status(): void
    {
        $this->assertSame(ContractorComplianceStatus::BLOCKED, $this->accessStatus());

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/blocking-rules", [
                'rules' => ['MISSING_MANDATORY_DOCUMENT' => false, 'EXPIRED_COID' => false],
            ])->assertRedirect()->assertSessionHasNoErrors();

        // The contractor in front of the person changing the rules moves immediately, rather than
        // at its next document upload.
        $this->assertSame(ContractorComplianceStatus::NON_COMPLIANT, $this->accessStatus());
    }

    public function test_changing_the_rules_needs_the_configure_entitlement(): void
    {
        // A clerk filing certificates must not be able to decide what refuses entry.
        $clerk = $this->userWith(['sheq.contractor.manage']);

        $this->actingAs($clerk)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/blocking-rules",
                ['rules' => ['MISSING_MANDATORY_DOCUMENT' => false]])
            ->assertForbidden();

        $this->assertTrue(app(ContractorComplianceStatus::class)
            ->rulesFor($this->companyId)['MISSING_MANDATORY_DOCUMENT']);
    }

    public function test_another_tenants_rules_do_not_apply_here(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'ccs-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorComplianceStatus::class)->saveRules($other,
            ['MISSING_MANDATORY_DOCUMENT' => false]);

        // Ours still blocks: a configuration is a tenant's own, and a rule set leaking between
        // them would let one company's choice admit another's contractors.
        $this->assertTrue(app(ContractorComplianceStatus::class)
            ->rulesFor($this->companyId)['MISSING_MANDATORY_DOCUMENT']);
    }
}
