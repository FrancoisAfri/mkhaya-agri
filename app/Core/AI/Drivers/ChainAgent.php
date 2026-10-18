<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Services\AIAuditService;
use App\Core\AI\Services\AIChatProviderChain;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Generator;

/**
 * A driver-system agent that delegates to the free-first provider chain
 * (config/ai.php → 'chat'). Registering this as the 'chain' provider and making it the
 * global default (config ai.provider) routes EVERY module that uses the driver system
 * (BaseModuleAI / AgentRegistry) through the same free-tier waterfall the Mkhaya
 * assistant uses — so no feature ever hits paid OpenAI until every free provider is
 * exhausted. The chain does its own multi-provider fallback + retry internally.
 */
class ChainAgent extends AbstractAgent
{
    public function __construct(AIAuditService $auditService, private AIChatProviderChain $chain)
    {
        parent::__construct($auditService);
    }

    public function providerName(): string
    {
        return 'chain';
    }

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $messages = $this->toMessages($request);

        $started = microtime(true);
        $res = $this->chain->complete($messages, [
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
            'module' => $request->module,
        ]);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $meta = $res['metadata'] ?? [];
        $failed = ($meta['error'] ?? null) === 'all_providers_failed';

        return new AgentResponse(
            content: (string) ($res['text'] ?? ''),
            provider: 'chain:' . ($meta['provider'] ?? 'none'),
            model: (string) ($meta['model'] ?? 'chain'),
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: (int) ($res['tokens'] ?? 0),
            durationMs: $durationMs,
            success: ! $failed,
            errorMessage: $failed ? 'All AI providers in the chain failed.' : null,
            raw: $res,
        );
    }

    public function stream(AgentRequest $request): Generator
    {
        // The chain is request/response only — emit the full answer as one chunk.
        yield $this->sendRequest($request)->content;
    }

    /** Build the OpenAI-style message list from the request (system + history + prompt). */
    private function toMessages(AgentRequest $request): array
    {
        $messages = [];

        $system = trim($request->systemPrompt);
        if ($request->responseFormat === 'json') {
            $system = trim($system . "\n\nRespond with ONLY valid JSON — no markdown fences, no commentary.");
        }
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($request->history as $h) {
            if (! empty($h['role']) && isset($h['content'])) {
                $messages[] = ['role' => (string) $h['role'], 'content' => (string) $h['content']];
            }
        }

        // The context is the data the prompt is about (the ticket, the lead, the tender). Every
        // other driver sends it via AbstractAgent::buildMessages; this one built its own message
        // list and dropped it, so a module asking "triage this ticket" with the ticket in context
        // sent the model a request with nothing to triage. Folded into the same user turn so the
        // chain's system+user shape, which every provider link accepts, is unchanged.
        $prompt = $request->prompt;
        if (! empty($request->context)) {
            $prompt = "### Context\n" . json_encode($request->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                . "\n\n### Request\n" . $prompt;
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }
}
