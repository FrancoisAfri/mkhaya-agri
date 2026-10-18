<?php

namespace App\Core\AI\Drivers;

use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Anthropic\Laravel\Facades\Anthropic;
use Generator;

class ClaudeAgent extends AbstractAgent
{
    public function providerName(): string
    {
        return 'claude';
    }

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $config    = config('ai.providers.claude');
        $startedAt = now();

        $params = [
            'model'      => $config['model'],
            'max_tokens' => $request->maxTokens ?? $config['max_tokens'],
            'messages'   => $this->buildMessages($request),
        ];

        if (! empty($request->systemPrompt)) {
            $params['system'] = $request->systemPrompt;
        }

        if ($request->responseFormat === 'json') {
            $params['system'] = ($params['system'] ?? '') .
                "\n\nYou must respond with valid JSON only. No prose, no markdown fences.";
        }

        $result = Anthropic::messages()->create($params);

        return new AgentResponse(
            content          : $result->content[0]->text,
            provider         : 'claude',
            model            : $result->model,
            promptTokens     : $result->usage->inputTokens,
            completionTokens : $result->usage->outputTokens,
            totalTokens      : $result->usage->inputTokens + $result->usage->outputTokens,
            durationMs       : now()->diffInMilliseconds($startedAt),
            success          : true,
            raw              : $result->toArray(),
        );
    }

    public function stream(AgentRequest $request): Generator
    {
        $config = config('ai.providers.claude');

        $stream = Anthropic::messages()->createStreamed([
            'model'      => $config['model'],
            'max_tokens' => $request->maxTokens ?? $config['max_tokens'],
            'messages'   => $this->buildMessages($request),
            'system'     => $request->systemPrompt ?: null,
        ]);

        foreach ($stream as $event) {
            if (isset($event->delta->text)) {
                yield $event->delta->text;
            }
        }
    }
}
