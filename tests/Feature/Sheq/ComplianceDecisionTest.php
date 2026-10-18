<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Compliance\ReasonCode;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ActivityService;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One compliance decision, with codes, and an honest answer when it cannot tell (COM-1, 2, 3).
 *
 * The defect this file is really about is not a wrong answer — it is three answers. The access
 * gate decided for itself whether somebody could come in. The QR pass verification decided
 * again, written separately, reading the same registers in a different order. The contractor
 * register computed a third verdict for its status column. So a worker could be refused at the
 * boom, show COMPLIANT in the register, and scan green on the pass in their own pocket, and
 * whichever answer a host saw first became the one they believed.
 *
 * Nothing detected that, because each path was tested against itself. The test that matters most
 * here is the one that asks all three the same question and requires the same answer.
 */
class ComplianceDecisionTest extends TestCase
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
            'code' => 'C'.Str::upper(Str::random(5)), 'name' => 'Mill', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Nothing demanded of the company, so any refusal in these tests came from the check
        // under test rather than from a document requirement left over in the settings.
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]),
                'compliance_unverified_outcome' => null, 'updated_at' => now()],
        );

        app(ContractorComplianceStatus::class)->saveRules($this->companyId, [], $this->user->id);

        $contractors = app(ContractorService::class);

        $this->contractor = $contractors->register($this->companyId, [
            'name' => 'Khanya Plant Hire', 'trade' => 'PLANT',
        ], $this->user->id);

        $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
            $this->user->id);

        $this->workerUuid = (string) Str::uuid();

        $this->workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => $this->workerUuid, 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Sipho Ndlovu',
            'trade' => 'PLANT',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ActivityService::class)->install($this->companyId, $this->user->id);
    }

    private function engine(): ComplianceDecisionService
    {
        return app(ComplianceDecisionService::class);
    }

    private function decide(?string $activity = null, ?int $siteId = null): array
    {
        return $this->engine()->forWorker($this->companyId, $this->workerId,
            $siteId ?? $this->siteId, $activity);
    }

    // ── COM-1: one service, twelve inputs ─────────────────────────────────────────────────────

    public function test_every_input_the_requirement_lists_is_actually_evaluated(): void
    {
        /*
         * COM-1 names twelve things the decision must consider. A requirement that lists its
         * inputs and an implementation that quietly checks eleven of them is the failure mode
         * here: nothing errors, and the twelfth condition never refuses anybody.
         *
         * Walked against the declared catalogue rather than a list written in this test, so
         * adding a check without declaring it, or declaring one without implementing it, both
         * fail.
         */
        $decision = $this->decide('WORKING_AT_HEIGHT');

        $evaluated = array_column($decision['checks'], 'key');

        foreach (array_keys(ComplianceDecisionService::CHECKS) as $declared) {
            $this->assertContains($declared, $evaluated,
                "The engine declares the {$declared} check and never runs it.");
        }

        foreach ($evaluated as $key) {
            $this->assertArrayHasKey($key, ComplianceDecisionService::CHECKS,
                "The engine runs a {$key} check that nothing declares, so no screen can label it.");
        }
    }

    public function test_every_check_says_which_of_the_four_states_it_is_in(): void
    {
        // A check with no state renders as neither a tick nor a cross, which on the one screen a
        // guard reads at a boom is worse than either.
        foreach ($this->decide('HOT_WORK')['checks'] as $check) {
            $this->assertContains($check['state'], [
                ComplianceDecisionService::PASS, ComplianceDecisionService::FAIL,
                ComplianceDecisionService::UNVERIFIED, ComplianceDecisionService::NOT_APPLICABLE,
            ], "The {$check['key']} check returned no usable state.");

            $this->assertNotSame('', trim($check['note']),
                "The {$check['key']} check explains nothing, so the screen shows a blank row.");
        }
    }

    public function test_a_clean_worker_is_allowed_and_says_so_in_one_word(): void
    {
        $decision = $this->decide();

        $this->assertSame(ComplianceDecisionService::ALLOWED, $decision['outcome'],
            implode(' | ', $decision['reasons']));
        $this->assertTrue($decision['allowed']);
        $this->assertSame([], $decision['codes']);
    }

    // ── COM-2: machine-readable reasons ───────────────────────────────────────────────────────

    public function test_a_refusal_carries_a_code_as_well_as_a_sentence(): void
    {
        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['inducted_on' => null, 'induction_expires' => null]);

        $decision = $this->decide();

        $this->assertSame(ComplianceDecisionService::DENIED, $decision['outcome']);

        // The code, for counting, charting, routing and translating.
        $this->assertContains(ReasonCode::INDUCTION_REQUIRED, $decision['codes']);

        // And the sentence, for the person at the boom, who cannot be handed a code.
        $this->assertStringContainsString('induction', strtolower(implode(' ', $decision['reasons'])));
        $this->assertStringContainsString('Sipho Ndlovu', implode(' ', $decision['reasons']));
    }

    public function test_every_code_the_engine_can_emit_is_in_the_catalogue(): void
    {
        /*
         * Walks the source for every ReasonCode reference the engine makes and requires the
         * catalogue to know it. A code the catalogue has never heard of is a string with extra
         * steps: nothing groups it, nothing translates it, and it looks exactly like a working
         * one on the screen that renders it.
         */
        $source = file_get_contents(
            app_path('Modules/Operations/Sheq/Compliance/ComplianceDecisionService.php'));

        preg_match_all('/ReasonCode::([A-Z_]+)/', $source, $matches);

        $referenced = array_unique($matches[1]);

        $this->assertNotEmpty($referenced);

        foreach ($referenced as $constant) {
            if (in_array($constant, ['CATALOGUE', 'REASONS'], true)) {
                continue;
            }

            $value = constant(ReasonCode::class.'::'.$constant);

            $this->assertTrue(ReasonCode::exists($value),
                "The engine emits {$value} and the catalogue does not list it.");
        }
    }

    public function test_every_catalogued_code_has_a_subject_and_a_translation_key(): void
    {
        foreach (ReasonCode::all() as $code) {
            // EVIDENCE_UNAVAILABLE is genuinely about the system rather than about a contractor,
            // a document or a person, and grouping it anywhere else would be a lie.
            if ($code === ReasonCode::EVIDENCE_UNAVAILABLE) {
                continue;
            }

            $this->assertNotSame('SYSTEM', ReasonCode::subjectOf($code),
                "The code {$code} has no subject, so every screen groups it under System.");

            $this->assertStringStartsWith('sheq.compliance.reason.', ReasonCode::keyOf($code));
        }
    }

    // ── COM-3: fail-safe ──────────────────────────────────────────────────────────────────────

    public function test_a_check_that_cannot_run_is_not_a_check_that_passed(): void
    {
        /*
         * The whole of COM-3 in one assertion. Simulated by asking about a worker whose
         * contractor row has been deleted underneath them — the compliance file cannot be built,
         * which is exactly the "mandatory compliance cannot be verified" case.
         */
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['deleted_at' => now()]);

        $decision = $this->engine()->forWorker($this->companyId, $this->workerId, $this->siteId);

        $this->assertFalse($decision['allowed'],
            'A decision that could not be established came back as permission to work.');
    }

    public function test_an_unverifiable_check_defaults_to_review_rather_than_refusal(): void
    {
        $decision = $this->unverifiableDecision();

        $this->assertSame(ComplianceDecisionService::REVIEW_REQUIRED, $decision['outcome']);
        $this->assertFalse($decision['allowed'],
            'REVIEW_REQUIRED was treated as permission to work.');
        $this->assertNotSame([], $decision['unverified'],
            'Nothing recorded which check could not be run, so nobody can fix it.');
    }

    public function test_the_host_chooses_what_an_unverifiable_check_does(): void
    {
        foreach ([ComplianceDecisionService::DENIED, ComplianceDecisionService::UNKNOWN,
            ComplianceDecisionService::REVIEW_REQUIRED] as $configured) {
            DB::table('sheq_settings')->where('company_id', $this->companyId)
                ->update(['compliance_unverified_outcome' => $configured]);

            $this->assertSame($configured, $this->unverifiableDecision()['outcome']);
        }
    }

    public function test_an_unreadable_setting_falls_back_to_the_shipped_behaviour(): void
    {
        // Not to "allow". A typo in a settings row must not be the thing that opens a gate.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['compliance_unverified_outcome' => 'YES_PLEASE']);

        $this->assertSame(ComplianceDecisionService::DEFAULT_UNVERIFIED_OUTCOME,
            $this->engine()->unverifiedOutcome($this->companyId));

        $this->assertFalse($this->unverifiableDecision()['allowed']);
    }

    public function test_a_real_failure_outranks_an_unverifiable_check(): void
    {
        /*
         * A host who chose DENIED for unverifiable evidence and also has a genuine failure must
         * see the failure, because the failure is the thing they can act on. "Could not verify"
         * sends somebody to debug a database; "induction expired" sends them to book an induction.
         */
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['compliance_unverified_outcome' => ComplianceDecisionService::DENIED]);

        // A failure from a DIFFERENT check than the one made unreadable, or the test would be
        // asserting that one check is both broken and failing, which proves nothing.
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['is_active' => false]);

        $decision = $this->unverifiableDecision();

        $this->assertSame(ComplianceDecisionService::DENIED, $decision['outcome']);
        $this->assertContains(ReasonCode::CONTRACTOR_INACTIVE, $decision['codes']);
        // And the broken check is still named, so somebody can go and fix it.
        $this->assertContains('INDUCTION', $decision['unverified']);
    }

    /** A decision in which one mandatory check genuinely cannot be run. */
    private function unverifiableDecision(): array
    {
        /*
         * The induction register is dropped for the length of the transaction. Crude, and the
         * honest way to produce the condition: COM-3 is about a check that THROWS rather than one
         * that finds nothing, and a mock would only prove the mock works.
         */
        // The roster's own induction dates have to be out of the way first, or the check answers
        // from those and never reaches the register being made unreadable.
        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['induction_expires' => null]);

        DB::statement('ALTER TABLE sheq_induction_completions RENAME TO sheq_induction_completions_x');

        try {
            return $this->engine()->forWorker($this->companyId, $this->workerId, $this->siteId);
        } finally {
            DB::statement('ALTER TABLE sheq_induction_completions_x RENAME TO sheq_induction_completions');
        }
    }

    // ── The three paths must agree ────────────────────────────────────────────────────────────

    public function test_the_gate_the_pass_and_the_engine_give_the_same_answer(): void
    {
        /*
         * The regression guard for the defect COM-1 describes. Each of these three was tested
         * against itself and passed; nothing asked them the same question side by side.
         *
         * Run across four states on purpose. Agreement when everybody says yes proves very
         * little — it is the refusals that used to diverge, because that is where each
         * implementation had made its own choice about what mattered.
         */
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['id_number_hash' => AccessDecisionService::hashIdentifier($identifier)]);

        app(SiteApprovalService::class)->decide($this->companyId, $this->contractor['uuid'],
            $this->siteId, ['status' => 'APPROVED'], $this->user->id);

        $states = [
            'clean' => fn () => null,
            'induction lapsed' => fn () => DB::table('sheq_contractor_workers')
                ->where('id', $this->workerId)->update([
                    'induction_expires' => Carbon::today()->subDay()->toDateString()]),
            'off the roster' => fn () => DB::table('sheq_contractor_workers')
                ->where('id', $this->workerId)->update(['is_active' => false]),
            'site blocked' => fn () => app(SiteApprovalService::class)->decide(
                $this->companyId, $this->contractor['uuid'], $this->siteId,
                ['status' => 'BLOCKED', 'reason' => 'Refused after the March incident.'],
                $this->user->id),
        ];

        foreach ($states as $label => $arrange) {
            $arrange();

            $engine = $this->engine()->forWorker($this->companyId, $this->workerId, $this->siteId);

            $gate = app(AccessDecisionService::class)
                ->decide($this->companyId, $identifier, $this->siteId);

            $token = app(AccessPassService::class)
                ->workerToken($this->companyId, $this->workerUuid, $this->user->id);

            $pass = app(AccessPassService::class)->verify($token, $this->siteId);

            /*
             * An inactive worker is unknown to the gate by design — `identify()` only matches a
             * live roster row, so the card simply stops working and the person is sent to
             * reception. Still a refusal, which is what is being compared.
             */
            $this->assertSame($engine['allowed'], $gate['allowed'],
                "The gate and the engine disagree when {$label}.");

            $this->assertSame($engine['allowed'], $pass['allowed'],
                "The scanned pass and the engine disagree when {$label}.");

            $this->assertSame($engine['outcome'], $pass['outcome'],
                "The scanned pass and the engine report different outcomes when {$label}.");
        }
    }

    public function test_the_gate_hands_on_the_codes_rather_than_only_the_sentences(): void
    {
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->where('id', $this->workerId)->update([
            'id_number_hash' => AccessDecisionService::hashIdentifier($identifier),
            'inducted_on' => null, 'induction_expires' => null,
        ]);

        $gate = app(AccessDecisionService::class)
            ->decide($this->companyId, $identifier, $this->siteId);

        $this->assertContains(ReasonCode::INDUCTION_REQUIRED, $gate['codes']);
        $this->assertSame(ComplianceDecisionService::DENIED, $gate['outcome']);
    }

    public function test_an_unknown_card_is_coded_too(): void
    {
        // Otherwise the most common refusal at any boom is the one nothing can count.
        $gate = app(AccessDecisionService::class)
            ->decide($this->companyId, 'no-such-card', $this->siteId);

        $this->assertSame([ReasonCode::WORKER_UNKNOWN], $gate['codes']);
    }

    // ── CON-26: what the work requires ────────────────────────────────────────────────────────

    public function test_an_activity_can_require_each_of_the_things_the_requirement_lists(): void
    {
        $activities = app(ActivityService::class);

        $uuid = $activities->save($this->companyId, null, [
            'code' => 'ROOF_WORK', 'name' => 'Roof work',
            'required_credentials' => ['WORK_AT_HEIGHT'],
            'requires_medical' => true, 'requires_induction' => true,
            'requires_risk_assessment' => true, 'requires_method_statement' => true,
            'requires_permit' => true, 'requires_equipment_certification' => true,
            'required_ppe' => ['FULL_BODY_HARNESS', 'hard_hat'],
        ], $this->user->id);

        $saved = collect($activities->all($this->companyId))->firstWhere('uuid', $uuid);

        foreach (['requires_medical', 'requires_induction', 'requires_risk_assessment',
            'requires_method_statement', 'requires_permit',
            'requires_equipment_certification'] as $flag) {
            $this->assertTrue((bool) $saved[$flag], "{$flag} did not survive being saved.");
        }

        // Normalised on the way in, so the gate never has to guess at a spelling.
        $this->assertSame(['FULL_BODY_HARNESS', 'HARD_HAT'], $saved['required_ppe']);
    }

    public function test_declaring_a_requirement_reports_before_it_refuses(): void
    {
        /*
         * The upgrade hazard, and the reason the per-activity flag and the blocking rule are two
         * separate decisions. `requires_permit` has shipped set on nine high-risk activities
         * since the catalogue arrived, and nothing acted on it. Acting on it the moment this
         * engine landed would turn away every working-at-height crew on every site that does not
         * raise permits in this system yet.
         */
        $decision = $this->decide('WORKING_AT_HEIGHT');

        $permit = collect($decision['checks'])->firstWhere('key', 'PERMIT');

        $this->assertSame(ComplianceDecisionService::PASS, $permit['state'],
            'A declared permit requirement refused a crew before anybody switched the rule on.');

        // Reported all the same. A rule switched off means "do not refuse for it", never
        // "stop checking" — the host still has to be able to see what is missing.
        $this->assertContains(ReasonCode::PERMIT_REQUIRED,
            array_column($decision['advisories'], 'code'));

        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            ['MISSING_PERMIT' => true], $this->user->id);

        $enforced = $this->decide('WORKING_AT_HEIGHT');

        $this->assertSame(ComplianceDecisionService::DENIED, $enforced['outcome']);
        $this->assertContains(ReasonCode::PERMIT_REQUIRED, $enforced['codes']);
    }

    public function test_the_shipped_catalogue_covers_the_work_the_requirement_names(): void
    {
        /*
         * CON-26 lists nine kinds of work by name. Welding and chemical handling were not in the
         * catalogue at all, and welding is not the same thing as hot work: hot work is the permit
         * regime, welding is a trade with its own qualification, and somebody grinding under a
         * hot-work permit is not a welder.
         */
        $codes = array_column(app(ActivityService::class)->all($this->companyId), 'code');

        foreach (['WORKING_AT_HEIGHT', 'HOT_WORK', 'CONFINED_SPACE', 'LIFTING_OPERATIONS',
            'EXCAVATION', 'ELECTRICAL_WORK', 'CHEMICAL_WORK', 'WELDING',
            'DEMOLITION'] as $required) {
            $this->assertContains($required, $codes,
                "CON-26 names {$required} and the shipped catalogue has no such activity.");
        }
    }

    public function test_ppe_is_reported_and_never_refused(): void
    {
        /*
         * A deliberate limit, not an omission. This system knows what the work requires; it
         * cannot know what the person is wearing. A refusal would be a guess, and it would be
         * wrong every time somebody was correctly kitted out.
         */
        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            array_fill_keys(ContractorComplianceStatus::CONFIGURABLE_RULES, true),
            $this->user->id);

        $ppe = collect($this->decide('WORKING_AT_HEIGHT')['checks'])->firstWhere('key', 'PPE');

        $this->assertSame(ComplianceDecisionService::PASS, $ppe['state']);
        $this->assertStringContainsString('harness', strtolower($ppe['note']));
    }

    public function test_an_expired_medical_refuses_whatever_the_rules_say(): void
    {
        /*
         * A certificate that has run out is not a gap in the filing — it is a qualification that
         * has lapsed, and refusing on it is the entire purpose of recording an expiry date. The
         * configurable rule governs how it affects the contractor's standing in the register,
         * which is a different question from whether this person may start work this morning.
         */
        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $this->workerId, 'kind' => 'MEDICAL',
            'title' => 'Certificate of fitness', 'status' => 'ACCEPTED',
            'expires_on' => Carbon::today()->subWeek()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            array_fill_keys(ContractorComplianceStatus::CONFIGURABLE_RULES, false),
            $this->user->id);

        $decision = $this->decide();

        $this->assertSame(ComplianceDecisionService::DENIED, $decision['outcome']);
        $this->assertContains(ReasonCode::MEDICAL_EXPIRED, $decision['codes']);
        // The date as stored, so a guard can repeat it over a radio without being misheard.
        $this->assertStringContainsString(Carbon::today()->subWeek()->toDateString(),
            implode(' ', $decision['reasons']));
    }

    public function test_a_contractor_can_be_asked_about_without_naming_a_person(): void
    {
        // "May this firm work here" is the register's question, and it must not answer it by
        // evaluating forty workers and reporting the worst of them.
        $decision = $this->engine()
            ->forContractor($this->companyId, $this->contractor['id'], $this->siteId);

        $keys = array_column($decision['checks'], 'key');

        $this->assertContains('CONTRACTOR', $keys);
        $this->assertNotContains('MEDICAL', $keys,
            'A contractor-level question evaluated a person who was never named.');
        $this->assertNull($decision['subject']['worker_id']);
    }

    public function test_a_decision_records_the_question_it_was_asked(): void
    {
        // An audit asking "were they eligible when they entered" needs the question back, not
        // only the answer. A stored verdict with no question is unusable a year later.
        $at = Carbon::parse('2026-05-04 06:12:00');

        $decision = $this->engine()->forWorker($this->companyId, $this->workerId,
            $this->siteId, 'hot_work', $at);

        $this->assertSame($this->siteId, $decision['question']['site_id']);
        $this->assertSame('HOT_WORK', $decision['question']['activity']);
        $this->assertSame('2026-05-04 06:12:00', $decision['question']['at']);
        $this->assertSame('Sipho Ndlovu', $decision['subject']['worker_name']);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_offers_the_question_and_explains_what_a_check_looks_at(): void
    {
        $props = $this->actingAs($this->user)->get('/sheq/compliance')
            ->assertOk()->viewData('page')['props'];

        $this->assertNotEmpty($props['workers'], 'The roster picker is empty.');
        $this->assertNotEmpty($props['sites']);
        $this->assertNotEmpty($props['activities']);

        // The declared checks, so the page says what this system looks at before anybody has
        // asked anything. That list is the argument for trusting the answer.
        $this->assertCount(count(ComplianceDecisionService::CHECKS), $props['checkCatalogue']);
        $this->assertSame(ComplianceDecisionService::DEFAULT_UNVERIFIED_OUTCOME,
            $props['unverifiedOutcome']);
    }

    public function test_the_page_runs_a_check_and_flashes_the_whole_decision(): void
    {
        $this->actingAs($this->user)
            ->post('/sheq/compliance/check', [
                'worker_uuid' => $this->workerUuid,
                'site_id' => $this->siteId,
                'activity' => 'WORKING_AT_HEIGHT',
            ])
            ->assertRedirect()
            ->assertSessionHas('decision', fn (array $decision) =>
                $decision['subject']['worker_name'] === 'Sipho Ndlovu'
                && count($decision['checks']) === count(ComplianceDecisionService::CHECKS));
    }

    public function test_the_fail_safe_is_saved_and_audited(): void
    {
        $this->actingAs($this->user)
            ->post('/sheq/compliance/fail-safe',
                ['outcome' => ComplianceDecisionService::DENIED])
            ->assertRedirect();

        $this->assertSame(ComplianceDecisionService::DENIED,
            $this->engine()->unverifiedOutcome($this->companyId));

        // On the record, because this setting decides whether a crew stands outside.
        $this->assertTrue(DB::table('sheq_audit_log')->where('company_id', $this->companyId)
            ->where('action', 'compliance.fail_safe_changed')->exists());
    }

    public function test_a_nonsense_fail_safe_is_refused_rather_than_stored(): void
    {
        // A value nothing understands reads as the default for ever, looking like a setting that
        // does nothing.
        $this->actingAs($this->user)
            ->post('/sheq/compliance/fail-safe', ['outcome' => 'ALLOWED'])
            ->assertSessionHasErrors('outcome');
    }

    public function test_the_fail_safe_cannot_be_changed_without_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/compliance/fail-safe',
                ['outcome' => ComplianceDecisionService::DENIED])
            ->assertForbidden();
    }

    // ── The screens the engine feeds ──────────────────────────────────────────────────────────

    public function test_every_reason_code_has_words_to_show_a_person(): void
    {
        /*
         * A code is the fact and never the label. `MEDICAL_EXPIRED` on a screen a site manager
         * reads is a demand that they learn an internal vocabulary — and a key nothing defines
         * has no failure mode in vue-i18n: it renders the key path, which looks like a label
         * until somebody actually reads it.
         *
         * Walked against the catalogue, so adding a code without labelling it fails here rather
         * than shipping `sheq.compliance.reason.whatever` onto a gate screen.
         */
        $locale = json_decode((string) file_get_contents(
            resource_path('js/utils/locales/modules/en.json')), true);

        $labels = $locale['sheq']['compliance']['reason'] ?? [];

        $this->assertNotEmpty($labels, 'No reason labels are defined at all.');

        foreach (ReasonCode::all() as $code) {
            $key = str_replace('sheq.compliance.reason.', '', ReasonCode::keyOf($code));

            $this->assertArrayHasKey($key, $labels,
                "The reason code {$code} has no label, so screens show the key path.");

            $this->assertNotSame('', trim((string) $labels[$key]));
        }
    }

    public function test_every_blocking_rule_has_words_too(): void
    {
        // The switches on the safety file screen render `sheq.safety_file.rule.<RULE>`. A rule
        // added to the engine and not labelled renders as that raw path next to a toggle that
        // decides whether people get through a gate.
        $locale = json_decode((string) file_get_contents(
            resource_path('js/utils/locales/modules/en.json')), true);

        $labels = $locale['sheq']['safety_file']['rule'] ?? [];

        foreach (ContractorComplianceStatus::CONFIGURABLE_RULES as $rule) {
            $this->assertArrayHasKey($rule, $labels,
                "The blocking rule {$rule} has no label on the screen that switches it.");
        }
    }

    public function test_the_activity_requirements_can_be_set_through_the_screen(): void
    {
        /*
         * The engine read seven requirement kinds and the catalogue screen could edit two of
         * them, so five were settable only by the installer — an engine with no door. A host
         * could not say that confined-space entry needs a medical.
         */
        $this->actingAs($this->user)->post('/sheq/activities', [
            'code' => 'ROOF_SHEETING', 'name' => 'Roof sheeting',
            'required_credentials' => ['WORK_AT_HEIGHT'],
            'requires_medical' => true,
            'requires_induction' => true,
            'requires_risk_assessment' => true,
            'requires_method_statement' => true,
            'requires_equipment_certification' => true,
            'requires_permit' => true,
            'is_high_risk' => true,
            'required_ppe' => ['FULL_BODY_HARNESS', 'hard hat'],
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $saved = collect(app(ActivityService::class)->all($this->companyId))
            ->firstWhere('code', 'ROOF_SHEETING');

        $this->assertNotNull($saved);

        foreach (['requires_medical', 'requires_induction', 'requires_risk_assessment',
            'requires_method_statement', 'requires_equipment_certification',
            'requires_permit'] as $flag) {
            $this->assertTrue((bool) $saved[$flag], "{$flag} did not survive the round trip.");
        }

        // Normalised on the way in, so the gate never has to guess at a spelling.
        $this->assertSame(['FULL_BODY_HARNESS', 'HARD HAT'], $saved['required_ppe']);
    }

    public function test_editing_an_activity_without_sending_the_requirements_leaves_them_alone(): void
    {
        /*
         * Absent-means-false is right for a form, which posts every box whether ticked or not,
         * and wrong for everything else. A caller renaming an activity without sending these
         * would silently switch off requirements somebody had set — and switching off a medical
         * requirement on confined-space entry is not a change anybody notices until the
         * consequences arrive.
         */
        $activities = app(ActivityService::class);

        $confined = $activities->find($this->companyId, 'CONFINED_SPACE');

        $this->assertTrue((bool) $confined->requires_medical,
            'The premise is wrong: the shipped catalogue does not set this.');

        $activities->save($this->companyId, $confined->uuid, [
            'code' => 'CONFINED_SPACE',
            'name' => 'Confined space entry (renamed)',
            'required_credentials' => ['CONFINED_SPACE'],
        ], $this->user->id);

        $after = $activities->find($this->companyId, 'CONFINED_SPACE');

        $this->assertSame('Confined space entry (renamed)', $after->name);
        $this->assertTrue((bool) $after->requires_medical,
            'A partial edit switched off a requirement it never mentioned.');
        $this->assertTrue((bool) $after->requires_risk_assessment);
    }

    public function test_the_catalogue_screen_hands_the_requirements_to_the_page(): void
    {
        // The list has to carry them or the editor opens with every switch off and saving
        // silently clears what the installer set.
        $rows = $this->actingAs($this->user)->get('/sheq/activities')
            ->assertOk()->viewData('page')['props']['rows'];

        $confined = collect($rows)->firstWhere('code', 'CONFINED_SPACE');

        $this->assertNotNull($confined);
        $this->assertTrue((bool) $confined['requires_medical']);
        $this->assertIsArray($confined['required_ppe']);
        $this->assertNotEmpty($confined['required_ppe']);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'cmp-'.Str::random(8).'@example.test',
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
}
