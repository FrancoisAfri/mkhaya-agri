<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\AI\Drivers\MockAgent;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight;
use App\Modules\Operations\DocumentManagement\Models\DocumentExtraction;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\DocumentAiService;
use App\Modules\Operations\DocumentManagement\Services\TextExtractor;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Summarising a document and asking questions about it.
 *
 * The two things that can go wrong here are not "the model is bad". They are: a document being sent
 * somewhere the organisation never agreed it could go, and an answer that sounds authoritative
 * about a document the model never read. Both are what these tests are for.
 */
class DocumentAiTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ai+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->user);

        // Every call goes to the mock driver, so nothing in this file reaches a real provider.
        config(['ai.overrides.document-management' => 'mock']);

        $this->settings(['ai_enabled' => true, 'ai_include_confidential' => false]);
    }

    private function settings(array $attributes): DocumentSetting
    {
        DocumentSetting::query()->delete();

        return DocumentSetting::create($attributes + ['max_file_size_mb' => 25]);
    }

    private function ai(): DocumentAiService
    {
        return app(DocumentAiService::class);
    }

    /** Points the mock at a canned answer for both tasks. */
    private function modelSays(string $content): void
    {
        $agent = app(AgentRegistry::class)->for('document-management');

        if (! $agent instanceof MockAgent) {
            $this->markTestSkipped('The mock AI driver is not resolvable in this environment.');
        }

        $agent->respondWith('document-management.summary', $content)
            ->respondWith('document-management.answer', $content);
    }

    /** A document with extracted text behind it, which is what the service actually reads. */
    private function document(string $text = 'The notice period is thirty days.', array $attributes = []): Document
    {
        $document = Document::create(array_merge([
            'title' => 'Service agreement', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'disk' => 'documents', 'path' => 'ai/' . Str::random(10) . '.txt',
            'original_name' => 'file.txt', 'stored_name' => 'file.txt',
            'extension' => 'txt', 'mime' => 'text/plain', 'size' => strlen($text),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        if ($text !== '') {
            DocumentExtraction::create([
                'document_id' => $document->id, 'version_id' => $version->id,
                'status' => TextExtractor::EXTRACTED, 'method' => 'plain',
                'text' => $text, 'char_count' => strlen($text), 'word_count' => str_word_count($text),
            ]);
        }

        return $document->fresh();
    }

    // ── Whether it may run at all ────────────────────────────────────────────

    public function test_it_is_off_until_the_company_switches_it_on(): void
    {
        // Sending a document to a model discloses it. That is a decision an organisation makes, not
        // one it discovers was made for it on the day of an upgrade.
        $this->settings(['ai_enabled' => false]);

        $result = $this->ai()->summarise($this->document());

        $this->assertNull($result['insight']);
        $this->assertSame('ai_disabled', $result['error']);
    }

    public function test_a_confidential_document_is_not_sent_without_a_second_decision(): void
    {
        $this->modelSays('A summary.');

        $confidential = $this->document('Salary details follow.', ['is_confidential' => true]);

        $this->assertSame('ai_confidential_excluded', $this->ai()->summarise($confidential)['error']);

        // And with the second switch on, it goes.
        $this->settings(['ai_enabled' => true, 'ai_include_confidential' => true]);
        $this->assertNull($this->ai()->summarise($confidential->fresh())['error']);
    }

    public function test_a_document_under_legal_hold_is_not_sent_to_a_third_party(): void
    {
        $this->modelSays('A summary.');

        // A document somebody is preserving for a matter is the last one to hand to an outside
        // service, whatever the confidentiality flag happens to say.
        $held = $this->document('Correspondence about the dispute.', ['is_legally_sensitive' => true]);

        $this->assertSame('ai_confidential_excluded', $this->ai()->summarise($held)['error']);
    }

    public function test_a_document_with_no_readable_text_says_so_rather_than_guessing_from_the_title(): void
    {
        /*
         * The failure this prevents is the ugly one: a scanned contract has no extracted text, and
         * a model handed only the title will happily describe what a document called "Service
         * agreement" usually says. That reads as a summary and is entirely invented.
         */
        $this->modelSays('A summary.');

        $scan = $this->document('');

        $result = $this->ai()->summarise($scan);

        $this->assertNull($result['insight']);
        $this->assertSame('ai_no_text', $result['error']);
    }

    // ── Summaries ────────────────────────────────────────────────────────────

    public function test_a_summary_is_generated_once_per_version_and_reused(): void
    {
        $this->modelSays('This is a service agreement with a thirty-day notice period.');

        $document = $this->document();

        $first = $this->ai()->summarise($document);
        $second = $this->ai()->summarise($document->fresh());

        $this->assertSame($first['insight']->id, $second['insight']->id);
        $this->assertSame(
            1,
            DocumentAiInsight::where('document_id', $document->id)->where('kind', DocumentAiInsight::SUMMARY)->count(),
            'Re-summarising on every view spends the allowance answering a question already answered.',
        );
    }

    public function test_the_document_text_is_what_is_sent_and_not_merely_its_title(): void
    {
        /*
         * The claim the whole feature rests on, so it is asserted against the prompt that actually
         * went out rather than against the reply. Sending only the title would still produce a
         * confident summary — of a document nothing had read.
         */
        $spy = $this->spyOnTheAgent();

        $document = $this->document('The indemnity is capped at five million rand.');

        $this->ai()->summarise($document);

        $this->assertNotNull($spy->last, 'Nothing was sent at all.');
        $this->assertStringContainsString('indemnity is capped at five million rand', $spy->last->prompt);
        $this->assertStringContainsString('only from the document text', $spy->last->systemPrompt);
    }

    public function test_a_document_longer_than_the_window_tells_the_model_its_view_is_partial(): void
    {
        // Otherwise it answers "the document does not mention X" about a document whose second half
        // it never saw, which is a wrong answer delivered with the confidence of a right one.
        $spy = $this->spyOnTheAgent();

        $long = str_repeat('Clause about liability and payment terms. ', 2000);

        $this->ai()->summarise($this->document($long));

        $this->assertNotNull($spy->last);
        $this->assertStringContainsString('the rest was not provided', $spy->last->prompt);
    }

    /**
     * Replaces the registry with one handing out an agent that records what it was asked.
     *
     * @return object{last: ?\App\Shared\DTOs\AgentRequest}
     */
    private function spyOnTheAgent(): object
    {
        $recorder = new class
        {
            public ?\App\Shared\DTOs\AgentRequest $last = null;
        };

        $agent = new class($recorder) implements \App\Core\AI\Contracts\AIAgentInterface
        {
            public function __construct(private readonly object $recorder) {}

            public function ask(\App\Shared\DTOs\AgentRequest $request): \App\Shared\DTOs\AgentResponse
            {
                $this->recorder->last = $request;

                return new \App\Shared\DTOs\AgentResponse(
                    content: 'A summary.', provider: 'spy', model: 'spy-1',
                    promptTokens: 1, completionTokens: 1, totalTokens: 2,
                    durationMs: 1, success: true,
                );
            }

            public function stream(\App\Shared\DTOs\AgentRequest $request): \Generator
            {
                yield 'A summary.';
            }

            public function providerName(): string
            {
                return 'spy';
            }
        };

        $registry = new class($agent) extends AgentRegistry
        {
            public function __construct(private readonly \App\Core\AI\Contracts\AIAgentInterface $agent) {}

            public function for(string $module): \App\Core\AI\Contracts\AIAgentInterface
            {
                return $this->agent;
            }

            public function forTask(string $module, ?string $task): \App\Core\AI\Contracts\AIAgentInterface
            {
                return $this->agent;
            }
        };

        $this->app->instance(AgentRegistry::class, $registry);

        return $recorder;
    }

    // ── Questions ────────────────────────────────────────────────────────────

    public function test_an_answer_is_recorded_with_the_question_that_was_asked(): void
    {
        // The log of what was asked is the only way to see afterwards what the assistant disclosed.
        $this->modelSays('The notice period is thirty days.');

        $document = $this->document();

        $result = $this->ai()->answer($document, 'What is the notice period?');

        $this->assertNotNull($result['insight']);
        $this->assertSame('What is the notice period?', $result['insight']->question);
        $this->assertSame($this->user->id, $result['insight']->created_by);
        $this->assertTrue($result['insight']->grounded);
    }

    public function test_a_refusal_is_stored_as_a_refusal_rather_than_as_an_answer(): void
    {
        /*
         * The distinction the whole feature rests on. A model that says the document does not cover
         * something has not answered the question, and recording that as an answer makes the
         * assistant look more capable than it is — which is how somebody comes to rely on one.
         */
        $this->modelSays('NOT_IN_DOCUMENT This document covers notice periods and fees only.');

        $result = $this->ai()->answer($this->document(), 'What is the governing law?');

        $this->assertNotNull($result['insight']);
        $this->assertFalse($result['insight']->grounded);
        $this->assertStringNotContainsString(
            'NOT_IN_DOCUMENT',
            $result['insight']->content,
            'The marker is for us, not for the reader.',
        );
    }

    public function test_an_empty_question_is_refused_before_anything_is_sent(): void
    {
        $this->modelSays('Something.');

        $result = $this->ai()->answer($this->document(), '   ');

        $this->assertNull($result['insight']);
        $this->assertSame('ai_no_question', $result['error']);
    }

    public function test_the_history_lists_questions_and_not_summaries(): void
    {
        $this->modelSays('An answer.');

        $document = $this->document();

        $this->ai()->summarise($document);
        $this->ai()->answer($document, 'First question?');
        $this->ai()->answer($document, 'Second question?');

        $history = $this->ai()->history($document);

        $this->assertCount(2, $history);
        $this->assertSame('Second question?', $history->first()->question);
    }

    // ── Through the route ────────────────────────────────────────────────────

    public function test_asking_requires_being_able_to_view_the_document(): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        // No document.view permission at all.
        $document = $this->document();

        $this->post(route('document-management.ai.ask', $document->uuid), ['question' => 'Anything?'])
            ->assertForbidden();

        $this->assertSame(0, DocumentAiInsight::where('document_id', $document->id)->count());
    }

    // ── The structured half of a summary ─────────────────────────────────────

    public function test_a_summary_keeps_its_structure_as_well_as_its_prose(): void
    {
        /*
         * A paragraph reads well and nothing but a person can act on it. "Expires 30 June 2027,
         * ninety days' notice, R450,000" as fields is the same information in a form somebody scans
         * in two seconds — and both are kept, because a photograph of a noticeboard has a summary
         * and no dates, amounts or obligations at all.
         */
        $spy = $this->spyReturning([
            'summary' => 'A service agreement running to June 2027.',
            'key_points' => ['Thirty days notice', 'Automatically renews'],
            'dates' => [['label' => 'Expires', 'date' => '2027-06-30']],
            'amounts' => [['label' => 'Contract value', 'value' => 'R450,000']],
            'risks' => ['Renews automatically unless cancelled'],
            'termination' => 'Either party may end it on thirty days written notice.',
        ]);

        $result = $this->ai()->summarise($this->document());

        $insight = $result['insight'];

        $this->assertNotNull($insight);
        $this->assertSame('A service agreement running to June 2027.', $insight->content);
        $this->assertSame(['Thirty days notice', 'Automatically renews'], $insight->data['key_points']);
        $this->assertSame('2027-06-30', $insight->data['dates'][0]['date']);
        $this->assertSame('R450,000', $insight->data['amounts'][0]['value']);
        $this->assertArrayNotHasKey('summary', $insight->data, 'The prose is kept once, not twice.');
    }

    public function test_a_section_returned_in_the_wrong_shape_is_dropped_rather_than_rendered(): void
    {
        // A model asked for a list occasionally answers with a string, and a section rendered from
        // the wrong type is a broken page rather than a missing one.
        $this->spyReturning([
            'summary' => 'A short memo.',
            'key_points' => 'this should have been a list',
            'dates' => [['label' => 'No date field here']],
            'obligations' => ['A real obligation'],
        ]);

        $insight = $this->ai()->summarise($this->document())['insight'];

        $this->assertArrayNotHasKey('key_points', $insight->data ?? []);
        $this->assertArrayNotHasKey('dates', $insight->data ?? []);
        $this->assertSame(['A real obligation'], $insight->data['obligations']);
    }

    public function test_a_document_that_reduces_to_nothing_still_gets_its_paragraph(): void
    {
        $this->spyReturning(['summary' => 'A photograph of a noticeboard.']);

        $insight = $this->ai()->summarise($this->document())['insight'];

        $this->assertSame('A photograph of a noticeboard.', $insight->content);
        $this->assertNull($insight->data);
    }

    /** An agent answering with a fixed JSON payload. */
    private function spyReturning(array $payload): object
    {
        $recorder = new class {
            public ?\App\Shared\DTOs\AgentRequest $last = null;
        };

        $agent = new class($recorder, $payload) implements \App\Core\AI\Contracts\AIAgentInterface {
            public function __construct(private readonly object $recorder, private readonly array $payload) {}

            public function ask(\App\Shared\DTOs\AgentRequest $request): \App\Shared\DTOs\AgentResponse
            {
                $this->recorder->last = $request;

                return new \App\Shared\DTOs\AgentResponse(
                    content: json_encode($this->payload), provider: 'spy', model: 'spy-1',
                    promptTokens: 1, completionTokens: 1, totalTokens: 2, durationMs: 1, success: true,
                );
            }

            public function stream(\App\Shared\DTOs\AgentRequest $request): \Generator { yield ''; }

            public function providerName(): string { return 'spy'; }
        };

        $registry = new class($agent) extends AgentRegistry {
            public function __construct(private readonly \App\Core\AI\Contracts\AIAgentInterface $agent) {}

            public function for(string $module): \App\Core\AI\Contracts\AIAgentInterface { return $this->agent; }

            public function forTask(string $module, ?string $task): \App\Core\AI\Contracts\AIAgentInterface { return $this->agent; }
        };

        $this->app->instance(AgentRegistry::class, $registry);

        return $recorder;
    }
}
