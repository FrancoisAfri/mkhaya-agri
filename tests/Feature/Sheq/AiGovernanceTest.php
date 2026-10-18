<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Ai\AiGuardrails;
use App\Modules\Operations\Sheq\Ai\AiSuggestionService;
use App\Modules\Operations\Sheq\Ai\EvaluationHarness;
use App\Modules\Operations\Sheq\Ai\KnowledgeRetriever;
use App\Modules\Operations\Sheq\Ai\Redactor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The §7 controls, tested as controls rather than as features.
 *
 * Each of these asserts that something is *impossible*, because that is what §7 actually specifies.
 * A test proving the AI can summarise an incident tells you nothing about whether the system is
 * safe to deploy; a test proving it cannot close one does.
 */
class AiGovernanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $otherCompanyId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->otherCompanyId = (int) (DB::table('companies')->where('id', '!=', $this->companyId)
            ->min('id') ?? $this->companyId + 9_000);
        $this->userId = $this->user();
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ai-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── SHEQ-AI-7: the reserved decisions ─────────────────────────────────────────────────────

    public function test_the_ai_cannot_take_any_decision_reserved_to_a_person(): void
    {
        $guardrails = app(AiGuardrails::class);

        // Every entry, not a sample. A guarded list is only worth having if all of it is enforced,
        // and this is the test that fails when somebody adds a capability without wiring it up.
        foreach (array_keys(AiGuardrails::FORBIDDEN) as $capability) {
            try {
                $guardrails->assertPermitted($capability);
                $this->fail("SHEQ-AI-7: an AI agent was permitted to {$capability}.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('SHEQ-AI-7', $e->getMessage());
                // The message must explain why, not just refuse — a developer who hits this needs
                // to understand the wall in the same moment they hit it.
                $this->assertGreaterThan(80, strlen($e->getMessage()));
            }
        }
    }

    public function test_the_forbidden_list_covers_every_decision_the_spec_reserves(): void
    {
        $forbidden = array_keys(AiGuardrails::FORBIDDEN);

        // §7 enumerates these explicitly: close records, approve permits, sign off actions,
        // alter risk ratings, submit statutory forms.
        foreach (['close_incident', 'close_action', 'approve_permit', 'sign_off',
            'alter_risk_rating', 'submit_statutory_form'] as $required) {
            $this->assertContains($required, $forbidden,
                "§7 reserves {$required} to a human, but the guardrails do not list it.");
        }
    }

    public function test_ordinary_capabilities_are_not_blocked(): void
    {
        $guardrails = app(AiGuardrails::class);

        // The control must be a scalpel. If it refused everything, agents would be useless and
        // somebody would route around the class entirely.
        foreach (['summarise_incident', 'suggest_controls', 'draft_toolbox_talk'] as $allowed) {
            $guardrails->assertPermitted($allowed);
        }

        $this->assertFalse($guardrails->isForbidden('summarise_incident'));
    }

    // ── SHEQ-AI-1: nothing reaches an operational table without a human ────────────────────────

    public function test_every_ai_output_starts_as_a_draft_attributed_to_the_agent(): void
    {
        $result = app(AiSuggestionService::class)->draft($this->companyId, [
            'agent' => 'INCIDENT_SUMMARISER',
            'title' => 'Draft summary',
            'content' => ['summary' => 'A worker was struck by a reversing vehicle.'],
        ]);

        $row = DB::table('sheq_ai_suggestions')->where('id', $result['id'])
            ->first(['status', 'actor_type', 'decided_by', 'decided_at']);

        $this->assertSame('DRAFT', $row->status);
        $this->assertSame('AI_AGENT', $row->actor_type);
        $this->assertNull($row->decided_by, 'A fresh draft must not appear to have been decided.');
        $this->assertNull($row->decided_at);
    }

    public function test_a_caller_cannot_create_a_pre_accepted_suggestion(): void
    {
        // The shortcut that would hollow out the whole control while leaving screens looking right.
        $result = app(AiSuggestionService::class)->draft($this->companyId, [
            'agent' => 'INCIDENT_SUMMARISER',
            'title' => 'Sneaky',
            'content' => ['x' => 1],
            'status' => 'ACCEPTED',
            'actor_type' => 'USER',
            'decided_by' => $this->userId,
        ]);

        $row = DB::table('sheq_ai_suggestions')->where('id', $result['id'])
            ->first(['status', 'actor_type', 'decided_by']);

        $this->assertSame('DRAFT', $row->status,
            'status is not a parameter — an agent must not be able to self-approve.');
        $this->assertSame('AI_AGENT', $row->actor_type,
            'actor_type is not a parameter — AI work must stay attributable as AI work.');
        $this->assertNull($row->decided_by);
    }

    public function test_a_decision_records_who_made_it(): void
    {
        $service = app(AiSuggestionService::class);

        $draft = $service->draft($this->companyId, [
            'agent' => 'CONTROL_SUGGESTER', 'title' => 'Controls', 'content' => ['a' => 1],
        ]);

        $service->accept($this->companyId, $draft['uuid'], $this->userId, 'Looks right.');

        $row = DB::table('sheq_ai_suggestions')->where('id', $draft['id'])
            ->first(['status', 'decided_by', 'decided_at']);

        $this->assertSame('ACCEPTED', $row->status);
        $this->assertSame($this->userId, (int) $row->decided_by);
        $this->assertNotNull($row->decided_at);
    }

    public function test_an_edit_is_kept_beside_the_original_not_over_it(): void
    {
        $service = app(AiSuggestionService::class);

        $draft = $service->draft($this->companyId, [
            'agent' => 'CONTROL_SUGGESTER', 'title' => 'Controls',
            'content' => ['control' => 'Issue gloves'],
        ]);

        $service->acceptEdited($this->companyId, $draft['uuid'], $this->userId,
            ['control' => 'Guard the machine, then issue gloves'], 'PPE alone is last resort.');

        $row = DB::table('sheq_ai_suggestions')->where('id', $draft['id'])
            ->first(['status', 'content', 'edited_content']);

        $this->assertSame('EDITED', $row->status);
        $this->assertStringContainsString('Issue gloves', (string) $row->content,
            'The original draft must survive — it is the evaluation signal.');
        $this->assertStringContainsString('Guard the machine', (string) $row->edited_content);
    }

    public function test_a_decision_cannot_be_made_twice(): void
    {
        $service = app(AiSuggestionService::class);

        $draft = $service->draft($this->companyId, [
            'agent' => 'X', 'title' => 'T', 'content' => [],
        ]);

        $service->accept($this->companyId, $draft['uuid'], $this->userId);

        $this->expectExceptionMessageMatches('/already accepted/i');
        $service->reject($this->companyId, $draft['uuid'], $this->userId, 'changed my mind');
    }

    public function test_a_rejection_must_carry_a_reason(): void
    {
        $service = app(AiSuggestionService::class);

        $draft = $service->draft($this->companyId, [
            'agent' => 'X', 'title' => 'T', 'content' => [],
        ]);

        $this->expectExceptionMessageMatches('/needs a reason/i');
        $service->reject($this->companyId, $draft['uuid'], $this->userId, '   ');
    }

    // ── SHEQ-AI-5: PII never leaves the boundary ──────────────────────────────────────────────

    public function test_personal_identifiers_are_stripped_before_text_leaves(): void
    {
        $redactor = app(Redactor::class);

        $text = 'Thabo Mokoena (8501015800083) was struck at 14:20. Contact 082 555 1234 or '
            .'thabo.mokoena@example.co.za for the next of kin.';

        $result = $redactor->redactWithNames($text, ['Thabo Mokoena']);

        $this->assertStringNotContainsString('8501015800083', $result['text']);
        $this->assertStringNotContainsString('thabo.mokoena@example.co.za', $result['text']);
        $this->assertStringNotContainsString('Thabo Mokoena', $result['text']);
        $this->assertFalse($redactor->containsIdentifiers($result['text']),
            'Redacted text still carries something that looks like a personal identifier.');
    }

    public function test_the_same_person_keeps_the_same_placeholder(): void
    {
        // Otherwise the model reads two people where the text described one, and the analysis it
        // produces is about a situation that never happened.
        $result = app(Redactor::class)->redact(
            'Call 082 555 1234. If no answer, try 082 555 1234 again, then 083 111 2222.'
        );

        preg_match_all('/\[PHONE_\d+\]/', $result['text'], $matches);

        $this->assertCount(3, $matches[0]);
        $this->assertCount(2, array_unique($matches[0]),
            'The repeated number must map to one placeholder, and the distinct one to another.');
    }

    public function test_redaction_is_reversible_inside_the_boundary(): void
    {
        $redactor = app(Redactor::class);
        $original = 'Sipho Ndlovu was injured; reach him on 083 111 2222.';

        $result = $redactor->redactWithNames($original, ['Sipho Ndlovu']);
        $restored = $redactor->restore($result['text'], $result['map']);

        // A practitioner reading "[PERSON_1] was injured" cannot act on it.
        $this->assertSame($original, $restored);
    }

    public function test_a_short_name_does_not_corrupt_unrelated_words(): void
    {
        $result = app(Redactor::class)->redactWithNames(
            'The grinder guard was missing on the line.', ['Al']
        );

        $this->assertStringContainsString('line', $result['text']);
        $this->assertStringNotContainsString('[PERSON', $result['text'],
            'A two-character name must not be matched — it is a substring, not a name.');
    }

    // ── SHEQ-AI-3: retrieval cannot cross a tenant or a site ───────────────────────────────────

    public function test_retrieval_never_returns_another_tenants_material(): void
    {
        $retriever = app(KnowledgeRetriever::class);

        $retriever->index($this->companyId, [
            'body' => 'Our confined space entry procedure requires a gas test before entry.',
            'citation' => 'Procedure CS-01', 'source_type' => 'DOCUMENT',
        ]);

        $foreign = $retriever->index($this->otherCompanyId, [
            'body' => 'Our confined space entry procedure requires a gas test before entry.',
            'citation' => 'Rival Procedure CS-99', 'source_type' => 'DOCUMENT',
        ]);

        $results = $retriever->retrieve($this->companyId, 'confined space gas test');

        $this->assertNotEmpty($results, 'The tenant should find its own material.');
        $this->assertNotContains($foreign, array_column($results, 'id'));

        foreach ($results as $chunk) {
            $this->assertStringNotContainsString('Rival', $chunk['citation']);
        }
    }

    public function test_a_site_sees_tenant_wide_material_but_not_a_sibling_site(): void
    {
        $retriever = app(KnowledgeRetriever::class);

        $tenantWide = $retriever->index($this->companyId, [
            'body' => 'Group standard: lockout tagout is mandatory on all rotating equipment.',
            'citation' => 'Group Standard LOTO-1', 'source_type' => 'POLICY',
        ]);

        $siteA = $retriever->index($this->companyId, [
            'body' => 'Site A lockout tagout register is kept at the workshop office.',
            'citation' => 'Site A LOTO note', 'source_type' => 'POLICY', 'site_id' => 8001,
        ]);

        $siteB = $retriever->index($this->companyId, [
            'body' => 'Site B lockout tagout register is kept at the plant gate.',
            'citation' => 'Site B LOTO note', 'source_type' => 'POLICY', 'site_id' => 8002,
        ]);

        $ids = array_column($retriever->retrieve($this->companyId, 'lockout tagout register', 8001), 'id');

        $this->assertContains($tenantWide, $ids, 'A site must see tenant-wide material.');
        $this->assertContains($siteA, $ids, 'A site must see its own material.');
        $this->assertNotContains($siteB, $ids, 'A site must never see a sibling site.');
    }

    public function test_a_natural_language_question_still_retrieves(): void
    {
        $retriever = app(KnowledgeRetriever::class);

        $chunk = $retriever->index($this->companyId, [
            'body' => 'Fall protection: a fall arrest system is required for work at height above '
                .'two metres, and a fall protection plan must be prepared by a competent person.',
            'citation' => 'Construction Regulations 10', 'source_type' => 'LEGISLATION',
        ]);

        // The failure mode this guards is silent. AND semantics would match nothing here, the model
        // would be told there was no source material, and it would politely decline — which reads
        // like the feature working rather than retrieval never having matched at all.
        $ids = array_column($retriever->retrieve($this->companyId,
            'what must I do about a fall from height at the plant?'), 'id');

        $this->assertContains($chunk, $ids,
            'A question phrased as a sentence must still retrieve the material that answers it.');
    }

    public function test_retrieval_ranks_the_better_match_first(): void
    {
        $retriever = app(KnowledgeRetriever::class);

        $retriever->index($this->companyId, [
            'body' => 'The canteen roster is posted weekly at the plant entrance.',
            'citation' => 'Notice 4', 'source_type' => 'DOCUMENT',
        ]);

        $onPoint = $retriever->index($this->companyId, [
            'body' => 'Noise induced hearing loss screening is required annually for employees in '
                .'designated noise zones at the plant.',
            'citation' => 'Noise Regulations 7', 'source_type' => 'LEGISLATION',
        ]);

        // ORing terms without ranking would make the weak match as likely as the right one, and
        // the prompt budget would fill with the canteen roster.
        $ids = array_column($retriever->retrieve($this->companyId,
            'noise induced hearing loss screening at the plant'), 'id');

        $this->assertSame($onPoint, $ids[0] ?? null);
    }

    public function test_there_is_no_unscoped_retrieval(): void
    {
        $this->expectExceptionMessageMatches('/SHEQ-AI-3/');
        app(KnowledgeRetriever::class)->retrieve(0, 'anything');
    }

    public function test_a_chunk_without_a_citation_cannot_be_indexed(): void
    {
        // Anything derived from it would be an uncitable assertion, which SHEQ-AI-2 forbids.
        $this->expectExceptionMessageMatches('/SHEQ-AI-2|citation/i');

        app(KnowledgeRetriever::class)->index($this->companyId, [
            'body' => 'Some material with no provenance.', 'source_type' => 'DOCUMENT',
        ]);
    }

    public function test_reindexing_the_same_content_does_not_duplicate_it(): void
    {
        $retriever = app(KnowledgeRetriever::class);

        $chunk = ['body' => 'Fall protection is required above two metres.',
            'citation' => 'Construction Regulations 10', 'source_type' => 'LEGISLATION'];

        $first = $retriever->index($this->companyId, $chunk);
        $second = $retriever->index($this->companyId, $chunk);

        $this->assertSame($first, $second,
            'A nightly re-index must not fill retrieval with copies of the same paragraph.');
    }

    // ── SHEQ-AI-6: the call log ───────────────────────────────────────────────────────────────

    public function test_a_human_decision_is_mirrored_onto_the_call_log(): void
    {
        $callLogId = DB::table('sheq_ai_call_log')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'agent' => 'INCIDENT_SUMMARISER', 'prompt_template' => 'summarise', 'prompt_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(AiSuggestionService::class);

        $draft = $service->draft($this->companyId, [
            'agent' => 'INCIDENT_SUMMARISER', 'title' => 'S', 'content' => [],
            'call_log_id' => $callLogId,
        ]);

        $service->reject($this->companyId, $draft['uuid'], $this->userId, 'Wrong root cause.');

        // §7.4's metrics are computed off this table; without the verdict here, acceptance rate is
        // unmeasurable.
        $this->assertSame('REJECTED',
            DB::table('sheq_ai_call_log')->where('id', $callLogId)->value('human_decision'));
    }

    // ── SHEQ-AI-2: uncited claims are surfaced, not hidden ────────────────────────────────────

    public function test_a_draft_with_uncited_claims_is_flagged(): void
    {
        $result = app(AiSuggestionService::class)->draft($this->companyId, [
            'agent' => 'LEGAL_ADVISOR', 'title' => 'Duties', 'content' => ['text' => '...'],
            'assertions' => [
                ['claim' => 'Section 24 requires reporting within 7 days.',
                    'citation' => 'OHS Act s24', 'verified' => true],
                ['claim' => 'A fine of R100 000 applies.', 'citation' => null, 'verified' => false],
            ],
        ]);

        $this->assertTrue($result['has_unverified_assertions'],
            'A draft containing an unsupported legal claim must say so on its face.');

        $stored = json_decode(DB::table('sheq_ai_suggestions')->where('id', $result['id'])
            ->value('assertions'), true);

        // Kept, not stripped: the unsupported sentence is the one a reviewer most needs to see.
        $this->assertCount(2, $stored);
        $this->assertSame('A fine of R100 000 applies.', $stored[1]['claim']);
    }

    // ── SHEQ-AI-4: no training corpus accumulates ─────────────────────────────────────────────

    public function test_nothing_accumulates_a_training_corpus(): void
    {
        // The control here is an absence, so the test asserts the absence. If a column named for
        // training or fine-tuning ever appears on these tables, somebody has started building the
        // thing §7 forbids and this is where it surfaces.
        foreach (['sheq_ai_suggestions', 'sheq_ai_call_log', 'sheq_knowledge_chunks'] as $table) {
            $columns = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                $this->assertDoesNotMatchRegularExpression('/train|finetune|fine_tune/i', $column,
                    "{$table}.{$column} suggests tenant data is being retained for training, "
                    .'which SHEQ-AI-4 forbids.');
            }
        }
    }

    // ── §7.4: the release gate ────────────────────────────────────────────────────────────────

    public function test_the_gate_refuses_to_pass_on_thin_evidence(): void
    {
        $harness = app(EvaluationHarness::class);
        $service = app(AiSuggestionService::class);

        // Three perfect drafts. Every rate is flawless, and that is exactly the situation where a
        // naive gate would report PASS on no evidence at all.
        for ($i = 0; $i < 3; $i++) {
            $draft = $service->draft($this->companyId, [
                'agent' => 'GATE_TEST', 'title' => "Draft {$i}", 'content' => [],
                'assertions' => [['claim' => 'A cited claim.', 'citation' => 'OHS Act s8',
                    'verified' => true]],
            ]);

            $service->accept($this->companyId, $draft['uuid'], $this->userId);
        }

        $gate = $harness->gate($this->companyId, 'GATE_TEST');

        $this->assertSame('INSUFFICIENT_DATA', $gate['verdict'],
            'A gate that passes on three samples launders a guess into an approval.');
        $this->assertSame(0.0, $gate['metrics']['hallucination_rate']);
        $this->assertStringContainsString('200', $gate['reasons'][0]);
    }

    public function test_unsupported_claims_drive_the_hallucination_rate(): void
    {
        $harness = app(EvaluationHarness::class);
        $service = app(AiSuggestionService::class);

        $service->draft($this->companyId, [
            'agent' => 'RATE_TEST', 'title' => 'D', 'content' => [],
            'assertions' => [
                ['claim' => 'Cited.', 'citation' => 'OHS Act s8', 'verified' => true],
                ['claim' => 'Cited too.', 'citation' => 'OHS Act s9', 'verified' => true],
                ['claim' => 'Made up.', 'citation' => null, 'verified' => false],
            ],
        ]);

        $metrics = $harness->score($this->companyId, 'RATE_TEST');

        $this->assertSame(3, $metrics['total_assertions']);
        $this->assertSame(1, $metrics['unsupported_assertions']);
        $this->assertEqualsWithDelta(0.3333, $metrics['hallucination_rate'], 0.001);
    }

    public function test_superseded_drafts_do_not_flatter_the_rates(): void
    {
        $service = app(AiSuggestionService::class);
        $harness = app(EvaluationHarness::class);

        $first = $service->draft($this->companyId, [
            'agent' => 'SUP_TEST', 'title' => 'First', 'content' => [],
            'subject_type' => 'INCIDENT', 'subject_id' => 424242,
        ]);

        $second = $service->draft($this->companyId, [
            'agent' => 'SUP_TEST', 'title' => 'Second', 'content' => [],
            'subject_type' => 'INCIDENT', 'subject_id' => 424242,
        ]);

        $service->supersedePrior($this->companyId, 'SUP_TEST', 'INCIDENT', 424242, $second['id']);
        $service->reject($this->companyId, $second['uuid'], $this->userId, 'Not right.');

        $metrics = $harness->score($this->companyId, 'SUP_TEST');

        // Two drafts exist, but only one was ever judged. Counting the superseded one as a
        // non-rejection would make an agent look better the more often it was re-run.
        $this->assertSame(2, $metrics['sample']);
        $this->assertSame(1, $metrics['decided']);
        $this->assertSame(1.0, $metrics['rejection_rate']);

        $this->assertSame('SUPERSEDED', DB::table('sheq_ai_suggestions')
            ->where('id', $first['id'])->value('status'));
    }

    // ── The single-door rule ──────────────────────────────────────────────────────────────────

    public function test_no_sheq_code_calls_the_provider_chain_except_the_client(): void
    {
        $offenders = [];
        $base = app_path('Modules/Operations/Sheq');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'SheqAiClient.php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (str_contains($contents, 'AIChatProviderChain')) {
                $offenders[] = str_replace($base.'/', '', $file->getPathname());
            }
        }

        // Every §7 guarantee — redaction, citation checking, telemetry — holds only because there
        // is exactly one path to a model. A second one would look entirely normal in review.
        $this->assertSame([], $offenders,
            'These files reach the model directly, bypassing redaction and the call log: '
            .implode(', ', $offenders));
    }
}
