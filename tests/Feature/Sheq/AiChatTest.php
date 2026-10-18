<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Ai\SheqAgents;
use App\Modules\Operations\Sheq\Ai\SheqAiClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Asking the AI Safety Officer a question.
 *
 * The model is replaced throughout. What is worth testing here is not whether a language model
 * writes good prose — it is the shape of what surrounds the call: that the question is confined to
 * safety and to this company's own material, that a conversation cannot become a route to a
 * decision reserved for a person, that a question files nothing into the review queue, and that a
 * provider being down degrades to "no answer" rather than to a broken screen.
 */
class AiChatTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'chat-' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Stand in for the model and capture exactly what it was asked.
     *
     * The request array is the whole subject of most of these tests: it carries the template that
     * confines the assistant, and asserting on it is how "restricted to safety" becomes a fact
     * about the code rather than a claim in a commit message.
     *
     * An ArrayObject holds the captures because it is shared by handle rather than copied — the
     * fake writes into the same instance the test reads afterwards.
     */
    private function captureClient(string $reply = 'A grounded answer [1].', bool $succeeded = true): \ArrayObject
    {
        $captured = new \ArrayObject;

        $fake = new class($captured, $reply, $succeeded) extends SheqAiClient
        {
            public function __construct(
                private \ArrayObject $captured,
                private string $reply,
                private bool $succeeded,
            ) {
                // Deliberately not calling parent::__construct: nothing here reaches a provider.
            }

            public function ask(int $companyId, array $request): array
            {
                $this->captured[] = ['company_id' => $companyId] + $request;

                return [
                    'text' => $this->succeeded ? $this->reply : '',
                    'assertions' => [
                        ['claim' => 'A grounded answer', 'citation' => 'OHS Act s8', 'verified' => true],
                    ],
                    'has_unverified' => false,
                    'call_log_id' => 1,
                    'chunks' => [],
                    'succeeded' => $this->succeeded,
                ];
            }
        };

        $this->app->instance(SheqAiClient::class, $fake);

        return $captured;
    }

    private function agents(): SheqAgents
    {
        return $this->app->make(SheqAgents::class);
    }

    // ── Restricted to safety, and to this company ────────────────────────────

    public function test_the_assistant_is_told_to_refuse_anything_that_is_not_safety(): void
    {
        $captured = $this->captureClient();

        $this->agents()->chat($this->companyId, 'What is the capital of France?', [], null, $this->userId);

        $template = $captured[0]['template'];

        $this->assertStringContainsString('outside what you can help with', $template,
            'Nothing in the instruction confines the assistant to safety.');
        $this->assertStringContainsString('do not answer it from general knowledge', $template);
    }

    public function test_the_assistant_is_told_to_say_when_the_material_does_not_cover_it(): void
    {
        $captured = $this->captureClient();

        $this->agents()->chat($this->companyId, 'How often must scaffolds be inspected?', [], null, $this->userId);

        // A plausible invention sends a practitioner away satisfied and wrong, which is worse than
        // an admission that the system holds no answer.
        $this->assertStringContainsString('does not cover a safety question, say exactly that',
            $captured[0]['template']);
    }

    public function test_the_question_is_asked_against_this_company_only(): void
    {
        $captured = $this->captureClient();

        $this->agents()->chat($this->companyId, 'Any open incidents?', [], null, $this->userId);

        // Retrieval is company-scoped by KnowledgeRetriever; this is the argument that scopes it.
        $this->assertSame($this->companyId, $captured[0]['company_id']);
    }

    // ── It cannot become a route to a decision ───────────────────────────────

    public function test_the_assistant_is_forbidden_from_claiming_it_has_acted(): void
    {
        $captured = $this->captureClient();

        $this->agents()->chat($this->companyId, 'Please close incident INC-1.', [], null, $this->userId);

        $template = $captured[0]['template'];

        // Saying "done" would have somebody believe a statutory duty was discharged when it was not.
        $this->assertStringContainsString('Never state or imply that you have closed, approved, signed', $template);
        $this->assertStringContainsString('name who has to take it', $template);
    }

    /**
     * The real control is not the wording above; it is that a chat turn writes nothing at all. A
     * prompt is not a security boundary, and this test is what says so in code.
     */
    public function test_a_conversation_writes_nothing(): void
    {
        $this->captureClient();

        $before = [
            'suggestions' => DB::table('sheq_ai_suggestions')->count(),
            'incidents' => DB::table('sheq_incidents')->count(),
        ];

        $this->agents()->chat($this->companyId, 'Close every incident and approve all permits.', [], null, $this->userId);

        $this->assertSame($before['suggestions'], DB::table('sheq_ai_suggestions')->count(),
            'A question was filed into the queue that exists for decisions.');
        $this->assertSame($before['incidents'], DB::table('sheq_incidents')->count());
    }

    // ── The conversation ─────────────────────────────────────────────────────

    public function test_earlier_turns_travel_with_the_question(): void
    {
        $captured = $this->captureClient();

        $this->agents()->chat($this->companyId, 'And the second one?', [
            ['role' => 'user', 'content' => 'What are the two worst hazards on site A?'],
            ['role' => 'assistant', 'content' => 'Working at height, and the guillotine.'],
        ], null, $this->userId);

        $prompt = $captured[0]['prompt'];

        $this->assertStringContainsString('worst hazards on site A', $prompt,
            '"And the second one?" cannot resolve without the turn before it.');
        $this->assertStringContainsString('And the second one?', $prompt);
    }

    /**
     * History goes in the prompt rather than as extra message turns, because redaction and citation
     * verification both operate on a single body of text — threading raw turns around the client
     * would route text past the redactor.
     */
    public function test_the_conversation_is_bounded(): void
    {
        $captured = $this->captureClient();

        $history = [];
        for ($i = 1; $i <= 30; $i++) {
            $history[] = ['role' => $i % 2 ? 'user' : 'assistant', 'content' => "Turn number {$i}"];
        }

        $this->agents()->chat($this->companyId, 'And now?', $history, null, $this->userId);

        $prompt = $captured[0]['prompt'];

        $this->assertStringNotContainsString('Turn number 1 ', $prompt . ' ',
            'The whole thread was sent, which pushes the retrieved material out of context.');
        $this->assertStringContainsString('Turn number 30', $prompt);
    }

    // ── When the provider is down ────────────────────────────────────────────

    public function test_a_model_being_unavailable_is_an_answer_rather_than_a_failure(): void
    {
        $this->captureClient(reply: '', succeeded: false);

        $result = $this->agents()->chat($this->companyId, 'Anything open?', [], null, $this->userId);

        $this->assertFalse($result['succeeded']);
        $this->assertSame('', $result['answer']);
    }

    public function test_an_empty_answer_counts_as_no_answer(): void
    {
        $this->captureClient(reply: '   ');

        $this->assertFalse($this->agents()->chat($this->companyId, 'Anything open?', [], null, $this->userId)['succeeded'],
            'Whitespace was reported to the practitioner as an answer.');
    }

    // ── The endpoint ─────────────────────────────────────────────────────────

    public function test_the_endpoint_answers_without_filing_anything(): void
    {
        $this->captureClient();

        $user = \App\Core\User\Models\User::find($this->userId);
        $before = DB::table('sheq_ai_suggestions')->count();

        $response = $this->actingAs($user)
            ->postJson(route('sheq.ai.chat'), ['message' => 'What must I do after a lost-time injury?']);

        if ($response->status() === 403) {
            $this->markTestSkipped('The SHEQ module gate is not open for this user in the test database.');
        }

        $response->assertOk()->assertJsonStructure(['succeeded', 'answer', 'citations', 'has_unverified']);
        $this->assertSame($before, DB::table('sheq_ai_suggestions')->count());
    }

    public function test_an_empty_question_is_refused(): void
    {
        $this->captureClient();

        $user = \App\Core\User\Models\User::find($this->userId);

        $response = $this->actingAs($user)->postJson(route('sheq.ai.chat'), ['message' => '']);

        if ($response->status() === 403) {
            $this->markTestSkipped('The SHEQ module gate is not open for this user in the test database.');
        }

        $response->assertStatus(422);
    }
}
