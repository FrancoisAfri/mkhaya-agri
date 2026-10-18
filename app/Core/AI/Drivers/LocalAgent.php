<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\AI\Services\AIAuditService;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Illuminate\Support\Facades\Log;
use OpenAI\Client;

/**
 * "Local" AI tier — the cheap workhorse for routine, high-volume tasks
 * (ticket triage, reply drafting, KB search, OCR cleanup, summaries).
 *
 * It speaks the OpenAI chat-completions protocol, so it works unchanged
 * against any OpenAI-compatible endpoint:
 *   - a hosted open-model provider (Groq / Together / OpenRouter / DeepSeek), or
 *   - an on-prem Ollama / vLLM server (base_url = http://127.0.0.1:11434/v1).
 *
 * Point it wherever you like via the LOCAL_AI_* env vars — no code change.
 */
class LocalAgent extends OpenAIAgent
{
    /** Own client — parent's is private and its constructor is bypassed. */
    protected Client $client;

    public function __construct(AIAuditService $auditService)
    {
        // Skip OpenAIAgent's constructor (which forces api.openai.com) and
        // build our own client aimed at the configured base URL.
        AbstractAgent::__construct($auditService);

        $config = config('ai.providers.local');

        $this->client = \OpenAI::factory()
            // Ollama needs no key; hosted providers do — send a placeholder
            // when empty so the SDK doesn't choke on a null Authorization header.
            ->withApiKey($config['api_key'] ?: 'no-key-required')
            ->withBaseUri($config['base_url'])
            ->make();
    }

    public function providerName(): string
    {
        return 'local';
    }

    /**
     * "Groq first, but never at the cost of availability." Try the local tier
     * (with its own retry/audit via the parent), and if it still fails —
     * rate-limit (429), timeout, outage — transparently fall back to a stronger
     * provider so no feature breaks. Configurable via ai.providers.local.fallback
     * ('openai' by default; set null to disable).
     */
    public function ask(AgentRequest $request): AgentResponse
    {
        $response = parent::ask($request);

        if (! $response->failed()) {
            return $response;
        }

        $fallback = config('ai.providers.local.fallback', 'openai');

        if (is_string($fallback) && $fallback !== '' && $fallback !== 'local'
            && $this->fallbackConfigured($fallback)) {
            Log::warning('Local AI tier failed — falling back', [
                'fallback' => $fallback,
                'module'   => $request->module,
                'error'    => $response->errorMessage,
            ]);

            return app(AgentRegistry::class)->driver($fallback)->ask($request);
        }

        return $response;
    }

    /** Only fall back to a provider that actually has credentials. */
    private function fallbackConfigured(string $provider): bool
    {
        $key = config("ai.providers.{$provider}.api_key");

        return is_string($key) && trim($key) !== '';
    }

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $config    = config('ai.providers.local');
        $startedAt = now();

        $messages = $this->buildOpenAIMessages($request);

        $params = [
            'model'       => $config['model'],
            'max_tokens'  => $request->maxTokens ?? $config['max_tokens'],
            'messages'    => $messages,
            'temperature' => $request->temperature,
        ];

        // Most OpenAI-compatible endpoints (Groq, Together, DeepSeek, recent
        // Ollama) honour JSON mode. Toggle off via config for the rare one
        // that rejects the parameter.
        if ($request->responseFormat === 'json' && ($config['supports_json'] ?? true)) {
            $params['response_format'] = ['type' => 'json_object'];
        }

        $result = $this->client->chat()->create($params);
        $choice = $result->choices[0];
        $usage  = $result->usage ?? null;

        return new AgentResponse(
            content          : $choice->message->content,
            provider         : 'local',
            model            : $result->model ?? $config['model'],
            promptTokens     : $usage->promptTokens ?? 0,
            completionTokens : $usage->completionTokens ?? 0,
            totalTokens      : $usage->totalTokens ?? 0,
            durationMs       : now()->diffInMilliseconds($startedAt),
            success          : true,
            raw              : $result->toArray(),
        );
    }
}
