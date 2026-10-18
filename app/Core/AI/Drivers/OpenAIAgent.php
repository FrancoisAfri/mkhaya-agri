<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Services\AIAuditService;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Generator;
use OpenAI\Client;
use OpenAI\Factory;

class OpenAIAgent extends AbstractAgent
{
    private Client $client;

    public function __construct(AIAuditService $auditService)
    {
        parent::__construct($auditService);

        $this->client = \OpenAI::factory()
            ->withApiKey(config('ai.providers.openai.api_key'))
            ->make();
    }

    public function providerName(): string
    {
        return 'openai';
    }

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $config    = config('ai.providers.openai');
        $startedAt = now();

        $messages = $this->buildOpenAIMessages($request);

        $params = [
            'model'      => $config['model'],
            'max_tokens' => $request->maxTokens ?? $config['max_tokens'],
            'messages'   => $messages,
            'temperature'=> $request->temperature,
        ];

        if ($request->responseFormat === 'json') {
            $params['response_format'] = ['type' => 'json_object'];
        }

        $result = $this->client->chat()->create($params);

        $choice  = $result->choices[0];
        $content = $choice->message->content;
        $usage   = $result->usage;

        return new AgentResponse(
            content          : $content,
            provider         : 'openai',
            model            : $result->model,
            promptTokens     : $usage->promptTokens,
            completionTokens : $usage->completionTokens,
            totalTokens      : $usage->totalTokens,
            durationMs       : now()->diffInMilliseconds($startedAt),
            success          : true,
            raw              : $result->toArray(),
        );
    }

    public function stream(AgentRequest $request): Generator
    {
        $config   = config('ai.providers.openai');
        $messages = $this->buildOpenAIMessages($request);

        $stream = $this->client->chat()->createStreamed([
            'model'    => $config['model'],
            'messages' => $messages,
        ]);

        foreach ($stream as $response) {
            $delta = $response->choices[0]->delta->content;
            if ($delta !== null) {
                yield $delta;
            }
        }
    }

    /**
     * Build OpenAI-format messages array.
     * Includes system message, context block, and user prompt.
     */
    protected function buildOpenAIMessages(AgentRequest $request): array
    {
        $messages = [];

        if (! empty($request->systemPrompt)) {
            $messages[] = [
                'role'    => 'system',
                'content' => $request->systemPrompt,
            ];
        }

        if (! empty($request->context)) {
            $messages[] = [
                'role'    => 'user',
                'content' => "### Context\n" . json_encode($request->context, JSON_PRETTY_PRINT),
            ];
            $messages[] = [
                'role'    => 'assistant',
                'content' => 'Context received. Ready for your question.',
            ];
        }

        foreach ($request->history as $turn) {
            if (isset($turn['role'], $turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $request->prompt,
        ];

        return $messages;
    }
}
