<?php

namespace Tests\Unit\AI;

use App\Core\AI\Drivers\ChainAgent;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The chain driver must send a request's context to the model, not just its prompt. */
class ChainAgentContextTest extends TestCase
{
    public function test_context_reaches_the_model(): void
    {
        config([
            'ai.chat.chain' => ['groq'],
            'ai.chat.claude_first' => [],
            'ai.chat.providers.groq' => ['driver' => 'openai', 'base_url' => 'https://groq.test/v1', 'key' => 'g', 'model' => 'm'],
        ]);
        Http::fake(['groq.test/*' => Http::response(['choices' => [['message' => ['content' => '{}']]], 'usage' => ['total_tokens' => 1]])]);

        $req = (new AgentRequest(prompt: 'Triage this ticket.', module: 'helpdesk'))
            ->withContext(['subject' => 'Printer on floor 2 is down']);

        app(ChainAgent::class)->ask($req);

        Http::assertSent(function ($r) {
            $user = collect($r['messages'])->firstWhere('role', 'user')['content'] ?? '';

            return str_contains($user, 'Printer on floor 2 is down') && str_contains($user, 'Triage this ticket.');
        });
    }
}
