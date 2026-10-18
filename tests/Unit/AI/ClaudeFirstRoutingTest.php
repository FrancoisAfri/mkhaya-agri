<?php

namespace Tests\Unit\AI;

use App\Core\AI\Services\AIChatProviderChain;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Claude link is reached only for the areas listed in ai.chat.claude_first, falls back to the
 * free chain when Claude fails or has no key, and leaves every other area untouched.
 */
class ClaudeFirstRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.chat.claude_first' => ['sheq'],
            'ai.chat.chain' => ['groq'],
            'ai.chat.providers.groq' => [
                'driver' => 'openai', 'base_url' => 'https://groq.test/v1', 'key' => 'g', 'model' => 'g-model',
            ],
            'ai.chat.providers.anthropic' => [
                'driver' => 'anthropic', 'base_url' => 'https://anthropic.test/v1', 'key' => 'sk-ant-x', 'model' => 'claude-haiku-4-5',
            ],
        ]);
    }

    private function fakeBoth(int $anthropicStatus = 200): void
    {
        Http::fake([
            'anthropic.test/*' => Http::response(
                ['model' => 'claude-haiku-4-5', 'content' => [['type' => 'text', 'text' => 'from claude']],
                 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
                $anthropicStatus,
            ),
            'groq.test/*' => Http::response(['choices' => [['message' => ['content' => 'from groq']]], 'usage' => ['total_tokens' => 3]]),
        ]);
    }

    public function test_listed_module_goes_to_claude_with_native_shape(): void
    {
        $this->fakeBoth();

        $r = app(AIChatProviderChain::class)->complete(
            [['role' => 'system', 'content' => 'SYS'], ['role' => 'user', 'content' => 'hi']],
            ['module' => 'sheq', 'max_tokens' => 300],
        );

        $this->assertSame('from claude', $r['text']);
        $this->assertSame('anthropic', $r['metadata']['provider']);
        $this->assertSame(15, $r['tokens']);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'anthropic.test/v1/messages')
                && $req->header('x-api-key')[0] === 'sk-ant-x'
                && $req->header('anthropic-version')[0] === '2023-06-01'
                && $req['system'] === 'SYS'
                && $req['max_tokens'] === 300
                && $req['messages'] === [['role' => 'user', 'content' => 'hi']];
        });
    }

    public function test_unlisted_module_never_touches_claude(): void
    {
        $this->fakeBoth();

        $r = app(AIChatProviderChain::class)->complete([['role' => 'user', 'content' => 'hi']], ['module' => 'payroll']);

        $this->assertSame('from groq', $r['text']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'anthropic.test'));
    }

    public function test_claude_failure_falls_back_to_the_free_chain(): void
    {
        $this->fakeBoth(529);

        $r = app(AIChatProviderChain::class)->complete([['role' => 'user', 'content' => 'hi']], ['module' => 'sheq']);

        $this->assertSame('from groq', $r['text']);
        $this->assertContains('anthropic', $r['metadata']['chain_tried']);
    }

    public function test_missing_key_skips_claude_silently(): void
    {
        config(['ai.chat.providers.anthropic.key' => null]);
        $this->fakeBoth();

        $r = app(AIChatProviderChain::class)->complete([['role' => 'user', 'content' => 'hi']], ['module' => 'sheq']);

        $this->assertSame('from groq', $r['text']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'anthropic.test'));
    }
}
