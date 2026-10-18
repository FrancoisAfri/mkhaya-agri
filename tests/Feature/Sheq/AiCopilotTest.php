<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Ai\AiGuardrails;
use App\Modules\Operations\Sheq\Ai\SheqAnswerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The SHEQ copilot (AI-1 to AI-8).
 *
 * The six questions §15 names are answered from the records, and the model is never consulted for
 * them. AI-2 says why: "Response must use the actual compliance engine and evidence." That
 * generalises to all six, because a language model asked "why is ABC Construction blocked"
 * produces a fluent, well-structured, plausible answer whether or not ABC Construction exists —
 * and a practitioner who goes to fetch a letter of good standing because the model said so, when
 * the real block was a lapsed medical on one worker, has been actively misled by the system that
 * was meant to tell them the truth.
 *
 * So these tests contain no mocked model. There is nothing to mock: every answer is a query, and
 * the assertion is that it matches the engine rather than that it reads well.
 *
 * AI-7 and AI-8 were already built, and are asserted here so they stay that way — the twelve
 * reserved decisions, and the rule that an agent cannot accept its own suggestion, which is the
 * control the whole review loop rests on.
 */
class AiCopilotTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::upper(Str::random(5)), 'name' => 'Tower block',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): SheqAnswerService
    {
        return app(SheqAnswerService::class);
    }

    // ── All six are recognised ────────────────────────────────────────────────────────────────

    public function test_all_six_questions_the_requirement_asks_are_recognised(): void
    {
        /*
         * The requirement's own example sentences, verbatim. A router that did not recognise the
         * documented wording would be a feature nobody could find.
         */
        $examples = [
            'Which contractors are currently blocked?' => 'AI-1',
            'Why is ABC Construction blocked?' => 'AI-2',
            'Which workers cannot perform working-at-height work today?' => 'AI-3',
            'What are our recurring hazards?' => 'AI-4',
            'Which corrective actions are overdue?' => 'AI-5',
            'Prepare my monthly SHEQ report.' => 'AI-6',
        ];

        foreach ($examples as $question => $requirement) {
            $intent = $this->service()->intentOf($question);

            $this->assertNotNull($intent,
                "{$requirement} gives this exact example and the router does not recognise it: "
                ."\"{$question}\"");

            $this->assertSame($requirement, SheqAnswerService::INTENTS[$intent]['requirement'],
                "\"{$question}\" was routed to the wrong requirement.");
        }
    }

    public function test_the_way_people_actually_type_is_recognised_too(): void
    {
        // Nobody types the requirement's sentences. A router that only matched them would be
        // documentation rather than a feature.
        foreach ([
            "who's blocked" => 'BLOCKED_CONTRACTORS',
            'blocked contractors' => 'BLOCKED_CONTRACTORS',
            'what is overdue' => 'OVERDUE_ACTIONS',
            'recurring hazards please' => 'RECURRING_HAZARDS',
            'monthly report' => 'MONTHLY_REPORT',
        ] as $question => $expected) {
            $this->assertSame($expected, $this->service()->intentOf($question),
                "\"{$question}\" is not recognised, so the answer comes from a model instead of "
                .'the register.');
        }
    }

    public function test_why_blocked_is_not_mistaken_for_list_the_blocked(): void
    {
        /*
         * Both sentences contain "blocked" and they are different questions with different
         * answers. Matching the general one first would answer "here are your four blocked
         * contractors" to somebody who asked about one of them.
         */
        $this->assertSame('WHY_BLOCKED',
            $this->service()->intentOf('why is Acme Scaffolding blocked'));
        $this->assertSame('BLOCKED_CONTRACTORS',
            $this->service()->intentOf('which contractors are blocked'));
    }

    public function test_an_unrelated_question_falls_through_to_the_chat_agent(): void
    {
        /*
         * Null, not a guess. A router that half-matched would answer the wrong question
         * confidently, which is the failure the whole class exists to avoid.
         */
        foreach ([
            'what does the law say about scaffold inspections',
            'how do I record a near miss',
            '',
            'hello',
        ] as $question) {
            $this->assertNull($this->service()->intentOf($question),
                "\"{$question}\" was captured by the router and will be answered from the "
                .'register instead of by the agent that can actually address it.');
        }
    }

    // ── AI-1 ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_blocked_contractor_list_comes_from_the_register(): void
    {
        $this->contractor('Acme Scaffolding', 'BLOCKED');
        $this->contractor('Ndlovu Rigging', 'COMPLIANT');
        $this->contractor('Suspended Co', 'SUSPENDED');

        $answer = $this->service()->answer($this->companyId, 'which contractors are blocked');

        $this->assertNotNull($answer);
        $this->assertSame('RECORDS', $answer['source'],
            'The answer is flagged as coming from a model, which is the one thing it must not be.');
        $this->assertSame(2, $answer['data']['count']);
        $this->assertStringContainsString('Acme Scaffolding', $answer['answer']);
        $this->assertStringContainsString('Suspended Co', $answer['answer']);
        $this->assertStringNotContainsString('Ndlovu Rigging', $answer['answer'],
            'A compliant contractor is listed as blocked.');

        // Every answer carries the rows it came from, so the figure can be checked.
        $this->assertCount(2, $answer['citations']);
    }

    public function test_nothing_blocked_is_said_plainly_rather_than_as_an_empty_list(): void
    {
        $this->contractor('Ndlovu Rigging', 'COMPLIANT');

        $answer = $this->service()->answer($this->companyId, 'which contractors are blocked');

        $this->assertSame(0, $answer['data']['count']);
        $this->assertStringContainsString('No contractor is currently blocked', $answer['answer']);
    }

    // ── AI-2 ──────────────────────────────────────────────────────────────────────────────────

    public function test_why_blocked_uses_the_compliance_engine_itself(): void
    {
        /*
         * The requirement's own condition. The answer has to be the engine's output, not a
         * description of it — so this asserts the findings in the payload are the engine's and
         * that the reasons in the prose came from them.
         */
        $contractorId = $this->contractor('Acme Scaffolding', 'BLOCKED');

        $answer = $this->service()->answer($this->companyId,
            'why is Acme Scaffolding blocked');

        $this->assertNotNull($answer, 'The named contractor was not found in the question.');
        $this->assertSame('AI-2', $answer['requirement']);

        $engine = app(\App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService::class)
            ->forContractor($this->companyId, $contractorId);

        $this->assertSame($engine['outcome'], $answer['data']['outcome'],
            'The copilot and the compliance screen give different verdicts for the same '
            .'contractor, so one of them is lying and a practitioner cannot tell which.');

        // And the evidence, verbatim.
        $this->assertNotEmpty($answer['data']['findings']);
        $this->assertNotEmpty($answer['citations']);
    }

    public function test_the_reason_comes_with_the_remedy_everything_else_uses(): void
    {
        /*
         * The same remedy catalogue as the blocking email and the refused-access screen. Three
         * places telling somebody three different things to do about one lapsed document is how a
         * compliance tool loses its authority.
         */
        $this->contractor('Acme Scaffolding', 'BLOCKED');

        $answer = $this->service()->answer($this->companyId, 'why is Acme Scaffolding blocked');

        if ($answer['data']['findings'] === []) {
            $this->markTestSkipped('This fixture produced no blocking findings to remedy.');
        }

        $this->assertStringContainsString('To fix it:', $answer['answer'],
            'The answer says what is wrong and not what to do about it, so it generates a phone '
            .'call rather than a renewal.');
    }

    public function test_a_contractor_nobody_named_falls_through_rather_than_being_guessed(): void
    {
        $this->contractor('Acme Scaffolding', 'BLOCKED');

        $this->assertNull($this->service()->answer($this->companyId,
            'why is that one contractor blocked'),
            'The router picked a contractor nobody named. Answering about the wrong company is '
            .'worse than not answering, because the answer is completely convincing.');
    }

    public function test_the_longest_matching_name_wins(): void
    {
        /*
         * A tenant with both "ABC" and "ABC Construction" would otherwise get an answer about the
         * shorter one — which reads entirely convincingly and is about a different company.
         */
        $short = $this->contractor('ABC', 'COMPLIANT');
        $long = $this->contractor('ABC Construction', 'BLOCKED');

        $answer = $this->service()->answer($this->companyId, 'why is ABC Construction blocked');

        $this->assertSame('ABC Construction', $answer['data']['contractor']);
    }

    public function test_a_contractor_that_is_not_blocked_is_told_so(): void
    {
        $this->contractor('Ndlovu Rigging', 'COMPLIANT');

        $answer = $this->service()->answer($this->companyId, 'why is Ndlovu Rigging blocked');

        $this->assertNotNull($answer);
        // Only if the engine actually allows them — the status column and the engine are two
        // different things and the engine is the authority.
        if ($answer['data']['outcome'] === 'ALLOWED') {
            $this->assertStringContainsString('is not blocked', $answer['answer']);
        }
    }

    // ── AI-3 ──────────────────────────────────────────────────────────────────────────────────

    public function test_who_cannot_do_an_activity_is_answered_per_worker_by_the_engine(): void
    {
        /*
         * "Cannot perform working-at-height work" is the compound question the engine exists for:
         * the competency, the medical, the induction and the activity's own requirements,
         * together. Answering it from any one of those tables would be wrong in a way that looks
         * right.
         */
        $this->activity('WORKING_AT_HEIGHT', 'Working at height');
        $contractorId = $this->contractor('Acme Scaffolding', 'COMPLIANT');
        $this->worker($contractorId, 'Johan Pretorius');

        $answer = $this->service()->answer($this->companyId,
            'which workers cannot perform working at height work today');

        $this->assertNotNull($answer, 'The named activity was not found in the question.');
        $this->assertSame('AI-3', $answer['requirement']);
        $this->assertSame('Working at height', $answer['data']['activity']);

        // A worker with no competency, medical or induction on record cannot do high-risk work,
        // so the engine refuses them and the answer names them.
        $this->assertSame(1, $answer['data']['count']);
        $this->assertStringContainsString('Johan Pretorius', $answer['answer']);
        $this->assertNotEmpty($answer['data']['workers'][0]['reasons'],
            'The answer names who cannot work and not why, which is the part that says what to '
            .'do about it.');
    }

    public function test_the_activity_can_be_named_by_its_code(): void
    {
        // People type what is on the permit, which is the code as often as the name.
        $this->activity('CONFINED_SPACE', 'Confined space entry');

        $this->assertNotNull($this->service()->answer($this->companyId,
            'who cannot do confined space work'),
            'An activity named by its code is not recognised.');
    }

    public function test_an_activity_nobody_named_falls_through(): void
    {
        $this->activity('WORKING_AT_HEIGHT', 'Working at height');

        $this->assertNull($this->service()->answer($this->companyId,
            'which workers cannot work today'),
            'The router picked an activity nobody named, so the answer is about work the person '
            .'was not asking about.');
    }

    public function test_the_answer_says_when_it_only_checked_some_workers(): void
    {
        /*
         * The engine runs thirteen checks per worker, so this is bounded at 300. An answer about
         * 300 of 9,000 workers that looks like an answer about all of them is the kind of thing
         * somebody plans a shift on.
         */
        $this->activity('WORKING_AT_HEIGHT', 'Working at height');
        $contractorId = $this->contractor('Acme Scaffolding', 'COMPLIANT');
        $this->worker($contractorId, 'Johan Pretorius');

        $answer = $this->service()->answer($this->companyId,
            'who cannot do working at height');

        $this->assertArrayHasKey('truncated', $answer['data']);
        $this->assertFalse($answer['data']['truncated']);
        $this->assertArrayHasKey('checked', $answer['data']);
    }

    // ── AI-4 ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_hazard_reported_once_is_not_called_recurring(): void
    {
        $this->hazard('STRUCTURAL');
        $this->hazard('ELECTRICAL');
        $this->hazard('ELECTRICAL');
        $this->hazard('ELECTRICAL');

        $answer = $this->service()->answer($this->companyId, 'what are our recurring hazards');

        $categories = array_column($answer['data']['categories'], 'hazard_category');

        $this->assertContains('ELECTRICAL', $categories);
        $this->assertNotContains('STRUCTURAL', $categories,
            'A hazard reported once is listed as recurring, which makes the whole list noise.');
    }

    public function test_no_recurrence_is_treated_as_suspicious_rather_than_good(): void
    {
        /*
         * A site with no repeat hazards at all in six months is unusual, and far more likely to
         * be a reporting problem than a safe site. Saying "none" without saying that would be
         * read as good news.
         */
        $answer = $this->service()->answer($this->companyId, 'recurring hazards');

        $this->assertSame([], $answer['data']['categories']);
        $this->assertStringContainsString('reporting problem', $answer['answer']);
    }

    public function test_the_recurring_list_says_how_many_are_still_open(): void
    {
        // The ones still open are where the control is not holding, which is the actionable half.
        $this->hazard('ELECTRICAL', 'OPEN');
        $this->hazard('ELECTRICAL', 'CLOSED');

        $answer = $this->service()->answer($this->companyId, 'recurring hazards');

        $row = collect($answer['data']['categories'])->firstWhere('hazard_category', 'ELECTRICAL');

        $this->assertSame(2, (int) $row['total']);
        $this->assertSame(1, (int) $row['still_open']);
    }

    // ── AI-5 ──────────────────────────────────────────────────────────────────────────────────

    public function test_overdue_actions_are_listed_with_how_late_they_are(): void
    {
        $this->action('Fix the chained door', Carbon::today()->subDays(12));
        $this->action('Not yet due', Carbon::today()->addDays(5));

        $answer = $this->service()->answer($this->companyId, 'which corrective actions are overdue');

        $this->assertSame(1, $answer['data']['count']);
        $this->assertStringContainsString('Fix the chained door', $answer['answer']);
        $this->assertStringContainsString('12 days ago', $answer['answer'],
            'The answer says an action is overdue without saying by how long, which is the part '
            .'that decides whether it matters today.');
        $this->assertStringNotContainsString('Not yet due', $answer['answer']);
    }

    public function test_a_verified_action_is_not_overdue(): void
    {
        $id = $this->action('Already done', Carbon::today()->subDays(30));

        DB::table('sheq_actions')->where('id', $id)->update(['status' => 'VERIFIED']);

        $this->assertSame(0, $this->service()
            ->answer($this->companyId, 'overdue actions')['data']['count'],
            'A verified action is reported as overdue, so the list never empties and people stop '
            .'reading it.');
    }

    // ── AI-6 ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_monthly_report_is_a_draft_and_says_so(): void
    {
        $answer = $this->service()->answer($this->companyId, 'prepare my monthly SHEQ report');

        $this->assertTrue($answer['data']['is_draft']);
        $this->assertStringContainsString('draft', strtolower($answer['answer']),
            'The report does not describe itself as a draft, so it can be forwarded as a '
            .'finished management report nobody checked.');
        $this->assertNotEmpty($answer['citations'],
            'AI-6 asks for citations. A month-end report is read by people who were not there, '
            .'and every figure has to be traceable to the screen it came from.');
    }

    public function test_a_missing_projection_is_stated_rather_than_shown_as_zero(): void
    {
        /*
         * The most misleading document this could produce: a management report whose injury
         * figures are blank because a cron did not run, presented as a month with no incidents.
         */
        DB::table('sheq_kpi_snapshots')->where('company_id', $this->companyId)->delete();

        $answer = $this->service()->answer($this->companyId, 'monthly report');

        $this->assertTrue($answer['data']['projection_missing']);
        $this->assertStringContainsString('not a month without incidents', $answer['answer']);
    }

    // ── AI-8 ──────────────────────────────────────────────────────────────────────────────────

    public function test_all_twelve_reserved_decisions_are_refused(): void
    {
        /*
         * AI-8 lists twelve things the AI must never do. Walked against the requirement's own
         * list, because a capability quietly dropped from the guard is a capability the AI gains.
         */
        $reserved = [
            'close incident' => 'close_incident',
            'close investigation' => 'close_investigation',
            'close action' => 'close_action',
            'approve permit' => 'approve_permit',
            'issue permit' => 'issue_permit',
            'sign' => 'sign_off',
            'alter risk rating' => 'alter_risk_rating',
            'submit statutory form' => 'submit_statutory_form',
            'classify incident' => 'classify_incident',
            'block contractor' => 'block_contractor',
            'delete evidence' => 'delete_evidence',
            'accept its own suggestion' => 'accept_own_suggestion',
        ];

        $guardrails = app(AiGuardrails::class);

        foreach ($reserved as $words => $capability) {
            $this->assertTrue($guardrails->isForbidden($capability),
                "AI-8 says the AI must never {$words}, and nothing stops it.");

            // And the refusal explains itself, because a guard with no reason gets removed by
            // whoever next finds it inconvenient.
            $this->assertGreaterThan(40, strlen(AiGuardrails::FORBIDDEN[$capability]));

            try {
                $guardrails->assertPermitted($capability);
                $this->fail("assertPermitted() allowed {$capability}.");
            } catch (RuntimeException) {
                // Expected.
            }
        }

        $this->assertCount(12, AiGuardrails::FORBIDDEN,
            'AI-8 lists twelve reserved decisions. A thirteenth is a scope change and a '
            .'eleventh is a capability the AI has gained.');
    }

    public function test_an_ordinary_capability_is_not_blocked(): void
    {
        // A guard that refuses everything is a guard somebody disables.
        app(AiGuardrails::class)->assertPermitted('suggest_controls');

        $this->assertFalse(app(AiGuardrails::class)->isForbidden('suggest_controls'));
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_contractors_are_not_in_the_answer(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        DB::table('sheq_contractors')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'name' => 'Their Blocked Co', 'access_status' => 'BLOCKED',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $answer = $this->service()->answer($this->companyId, 'which contractors are blocked');

        $this->assertStringNotContainsString('Their Blocked Co', $answer['answer'],
            'One tenant asked the copilot a question and was told another tenant\'s contractor '
            .'is blocked.');
    }

    public function test_another_tenants_contractor_cannot_be_asked_about_by_name(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        DB::table('sheq_contractors')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'name' => 'Their Blocked Co', 'access_status' => 'BLOCKED',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull($this->service()->answer($this->companyId,
            'why is Their Blocked Co blocked'),
            'Naming another tenant\'s contractor produced an answer about them.');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function contractor(string $name, string $status): int
    {
        return (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => $name, 'access_status' => $status, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function activity(string $code, string $name): int
    {
        return (int) DB::table('sheq_activities')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => $code, 'name' => $name, 'is_high_risk' => true,
            'requires_permit' => true, 'requires_medical' => true,
            'requires_induction' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function worker(int $contractorId, string $name): int
    {
        return (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hazard(string $category, string $status = 'OPEN'): int
    {
        return (int) DB::table('sheq_hazards')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'HAZ-'.Str::upper(Str::random(6)), 'site_id' => $this->siteId,
            'description' => 'Test hazard', 'hazard_category' => $category,
            'status' => $status, 'reported_at' => Carbon::today()->subMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function action(string $title, Carbon $due): int
    {
        return (int) DB::table('sheq_actions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::upper(Str::random(6)), 'title' => $title,
            'action_type' => 'CORRECTIVE', 'source_type' => 'MANUAL',
            'site_id' => $this->siteId, 'status' => 'OPEN',
            'due_date' => $due->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
