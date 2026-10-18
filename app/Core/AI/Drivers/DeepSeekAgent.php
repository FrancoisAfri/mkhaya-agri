<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Services\AIAuditService;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;

class DeepSeekAgent extends OpenAIAgent
{
    public function __construct(AIAuditService $auditService)
    {
        // Bypass OpenAIAgent constructor — build our own client
        // pointing at DeepSeek's base URL
        \App\Core\AI\Drivers\AbstractAgent::__construct($auditService);

        $config = config('ai.providers.deepseek');

        $this->client = \OpenAI::factory()
            ->withApiKey($config['api_key'])
            ->withBaseUri($config['base_url'])
            ->make();
    }

    public function providerName(): string
    {
        return 'deepseek';
    }

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $config    = config('ai.providers.deepseek');
        $startedAt = now();

        $messages = $this->buildOpenAIMessages($request);

        $params = [
            'model'       => $config['model'],
            'max_tokens'  => $request->maxTokens ?? $config['max_tokens'],
            'messages'    => $messages,
            'temperature' => $request->temperature,
        ];

        // DeepSeek supports JSON mode identically to OpenAI
        if ($request->responseFormat === 'json') {
            $params['response_format'] = ['type' => 'json_object'];
        }

        $result  = $this->client->chat()->create($params);
        $choice  = $result->choices[0];
        $usage   = $result->usage;

        return new AgentResponse(
            content          : $choice->message->content,
            provider         : 'deepseek',
            model            : $result->model,
            promptTokens     : $usage->promptTokens,
            completionTokens : $usage->completionTokens,
            totalTokens      : $usage->totalTokens,
            durationMs       : now()->diffInMilliseconds($startedAt),
            success          : true,
            raw              : $result->toArray(),
        );
    }
}
