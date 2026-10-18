<?php

namespace App\Core\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cost-control router for the Mkhaya assistant. Walks the configured provider chain
 * (config/ai.php → 'chat.chain') IN ORDER and returns the first provider that has an
 * API key and returns a valid answer. Every provider before 'openai' is a free /
 * free-tier endpoint, so the paid OpenAI is only reached when all free providers are
 * unconfigured, rate-limited, or failing.
 *
 * Note: the bulk of chat volume never reaches here at all — procedural and data
 * questions are answered straight from the system (HowToKnowledgeService + ERP data)
 * with zero AI tokens. This chain only handles the genuinely open-ended questions.
 */
class AIChatProviderChain
{
    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @param array{temperature?:float, max_tokens?:int} $options
     * @return array{text:string, tokens:int, metadata:array}
     */
    public function complete(array $messages, array $options = []): array
    {
        $cfg = config('ai.chat', []);
        $chain = $cfg['chain'] ?? ['openai'];
        $providers = $cfg['providers'] ?? [];
        $temperature = (float) ($options['temperature'] ?? $cfg['temperature'] ?? 0.3);
        $timeout = (int) ($cfg['timeout'] ?? 20);
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : null;

        $tried = [];

        // Claude-first areas: move the anthropic link to the front for this call only. The rest of
        // the chain stays behind it as the fallback, so a Claude outage or a missing key degrades
        // to exactly the behaviour the area had before.
        $module = (string) ($options['module'] ?? '');
        $prefer = $options['prefer'] ?? (
            $module !== '' && in_array($module, (array) ($cfg['claude_first'] ?? []), true) ? 'anthropic' : null
        );
        if ($prefer !== null && isset($providers[$prefer])) {
            $chain = array_values(array_unique(array_merge([$prefer], $chain)));
        }

        foreach ($chain as $name) {
            $p = $providers[$name] ?? null;
            if (! $p) {
                continue;
            }

            // A provider whose service has been withdrawn cannot come back, and trying it costs
            // real time on every request — the retired GitHub endpoint stopped resolving, so each
            // attempt waited out a DNS timeout before moving on. Skipped here rather than only in
            // the default chain because installations pin AI_CHAT_CHAIN in their own .env.
            if (! empty($p['retired'])) {
                continue;
            }

            // Must have an endpoint, and a key unless the provider is keyless (Ollama).
            $keyless = ! empty($p['keyless']);
            if (empty($p['base_url']) || (empty($p['key']) && ! $keyless)) {
                continue;
            }

            try {
                $driver = $p['driver'] ?? 'openai';
                $result = match ($driver) {
                    'gemini'    => $this->callGemini($p, $messages, $temperature, $timeout),
                    'anthropic' => $this->callAnthropic($p, $messages, $temperature, $timeout, $maxTokens),
                    default     => $this->callOpenAiCompatible($p, $messages, $temperature, $timeout, $maxTokens),
                };

                if (! empty($result['text'])) {
                    $result['metadata']['provider'] = $name;
                    $result['metadata']['chain_tried'] = $tried;
                    return $result;
                }

                $tried[] = $name;
            } catch (\Throwable $e) {
                $tried[] = $name;
                Log::warning("AI chat provider [{$name}] failed, trying next in chain.", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('AI chat: every provider in the chain failed.', ['tried' => $tried]);

        return [
            'text' => '⚠️ The AI assistant is unavailable right now. Please try again shortly.',
            'tokens' => 0,
            'metadata' => ['error' => 'all_providers_failed', 'chain_tried' => $tried],
        ];
    }

    /**
     * Convenience for the self-built module clients: one system+user turn in, plain
     * answer text out (null if every provider failed). Lets those services route
     * through the same free-first chain without rebuilding the message array.
     */
    /**
     * Pass `['humanize' => true]` for anything a customer will read.
     *
     * Two things happen: the writing rules go into the system prompt so the model avoids the
     * house-style tells while writing, and the mechanical ones are stripped out of whatever comes
     * back anyway. Analysis calls that return JSON leave it off — the rules are about prose, and
     * cleaning a JSON payload would corrupt it.
     */
    public function text(string $system, string $user, array $options = []): ?string
    {
        $humanize = (bool) ($options['humanize'] ?? false);
        unset($options['humanize']);

        if ($humanize) {
            $system = app(HumanizerService::class)->applyTo($system);
        }

        $messages = [];
        if (trim($system) !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $user];

        $res = $this->complete($messages, $options);
        if (($res['metadata']['error'] ?? null) === 'all_providers_failed') {
            return null;
        }

        $text = $res['text'] ?? null;

        // clean() declines a structured payload on its own; this keeps the intent visible here
        // rather than depending on a guard three files away.
        return $humanize && $text !== null ? app(HumanizerService::class)->clean($text) : $text;
    }

    /** Standard OpenAI-compatible /chat/completions (Groq, OpenRouter, DeepSeek, OpenAI, …). */
    private function callOpenAiCompatible(array $p, array $messages, float $temperature, int $timeout, ?int $maxTokens = null): array
    {
        $payload = [
            'model' => $p['model'],
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($maxTokens !== null && $maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        $req = Http::timeout($timeout)->retry(1, 300);
        // Keyless endpoints (Ollama) take no auth header.
        $req = empty($p['key']) ? $req : $req->withToken($p['key']);

        $res = $req->post(rtrim($p['base_url'], '/') . '/chat/completions', $payload)
            ->throw()
            ->json();

        $text = $res['choices'][0]['message']['content'] ?? '';
        $tokens = (int) ($res['usage']['total_tokens'] ?? 0);

        return [
            'text' => is_array($text) ? '' : (string) $text,
            'tokens' => $tokens,
            'metadata' => ['model' => $p['model'], 'tokens_used' => $tokens],
        ];
    }

    /**
     * Anthropic Messages API. Differs from the OpenAI shape in three ways that matter: the system
     * prompt is a top-level field rather than a message, max_tokens is REQUIRED, and auth is
     * x-api-key + anthropic-version rather than a bearer token.
     */
    private function callAnthropic(array $p, array $messages, float $temperature, int $timeout, ?int $maxTokens = null): array
    {
        $system = '';
        $turns = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= $m['content'] . "\n";
                continue;
            }
            $turns[] = [
                'role' => ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($m['content'] ?? ''),
            ];
        }

        $body = [
            'model' => $p['model'],
            'max_tokens' => $maxTokens !== null && $maxTokens > 0 ? $maxTokens : 4096,
            'temperature' => min(1.0, max(0.0, $temperature)),
            'messages' => $turns,
        ];
        if (trim($system) !== '') {
            $body['system'] = trim($system);
        }

        $res = Http::timeout(max($timeout, 45))->retry(1, 300)
            ->withHeaders(['x-api-key' => $p['key'], 'anthropic-version' => '2023-06-01'])
            ->post(rtrim($p['base_url'], '/') . '/messages', $body)
            ->throw()
            ->json();

        $text = '';
        foreach ((array) ($res['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $in = (int) ($res['usage']['input_tokens'] ?? 0);
        $out = (int) ($res['usage']['output_tokens'] ?? 0);

        return [
            'text' => $text,
            'tokens' => $in + $out,
            'metadata' => [
                'model' => (string) ($res['model'] ?? $p['model']),
                'tokens_used' => $in + $out,
                'prompt_tokens' => $in,
                'completion_tokens' => $out,
            ],
        ];
    }

    /** Google Gemini native generateContent (messages → contents + systemInstruction). */
    private function callGemini(array $p, array $messages, float $temperature, int $timeout): array
    {
        $system = '';
        $contents = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= $m['content'] . "\n";
                continue;
            }
            $contents[] = [
                'role' => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ];
        }

        $body = ['contents' => $contents, 'generationConfig' => ['temperature' => $temperature]];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $url = rtrim($p['base_url'], '/') . "/models/{$p['model']}:generateContent?key=" . urlencode($p['key']);

        $res = Http::timeout($timeout)->retry(1, 300)->post($url, $body)->throw()->json();

        $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $tokens = (int) ($res['usageMetadata']['totalTokenCount'] ?? 0);

        return [
            'text' => (string) $text,
            'tokens' => $tokens,
            'metadata' => ['model' => $p['model'], 'tokens_used' => $tokens],
        ];
    }
}
