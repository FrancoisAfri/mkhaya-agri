<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Services\AIAuditService;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class GeminiAgent extends AbstractAgent
{
    public function __construct(AIAuditService $auditService)
    {
        parent::__construct($auditService);
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $config    = config('ai.providers.gemini');
        $startedAt = now();

        $url = sprintf(
            '%s/models/%s:generateContent?key=%s',
            rtrim($config['base_url'], '/'),
            $config['model'],
            $config['api_key'],
        );

        $payload = $this->buildPayload($request, $config);

        $httpResponse = Http::timeout(60)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $payload)
            ->throw();

        $body = $httpResponse->json();

        $this->assertNoGeminiError($body);

        $content    = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $usageMeta  = $body['usageMetadata'] ?? [];

        $promptTokens     = $usageMeta['promptTokenCount']     ?? 0;
        $completionTokens = $usageMeta['candidatesTokenCount'] ?? 0;

        return new AgentResponse(
            content          : $content,
            provider         : 'gemini',
            model            : $config['model'],
            promptTokens     : $promptTokens,
            completionTokens : $completionTokens,
            totalTokens      : $promptTokens + $completionTokens,
            durationMs       : now()->diffInMilliseconds($startedAt),
            success          : true,
            raw              : $body,
        );
    }

    public function stream(AgentRequest $request): Generator
    {
        $config = config('ai.providers.gemini');

        $url = sprintf(
            '%s/models/%s:streamGenerateContent?key=%s&alt=sse',
            rtrim($config['base_url'], '/'),
            $config['model'],
            $config['api_key'],
        );

        $payload = $this->buildPayload($request, $config);

        // Gemini SSE streaming — yield each text chunk
        $response = Http::timeout(120)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withOptions(['stream' => true])
            ->post($url, $payload);

        $body = $response->getBody();

        while (! $body->eof()) {
            $line = $this->readSseLine($body);

            if (str_starts_with($line, 'data: ')) {
                $json  = substr($line, 6);
                $chunk = json_decode($json, true);
                $text  = $chunk['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if ($text !== null) {
                    yield $text;
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPayload(AgentRequest $request, array $config): array
    {
        $parts = [];

        // Combine system prompt + context into a preamble text part
        $preamble = '';

        if (! empty($request->systemPrompt)) {
            $preamble .= $request->systemPrompt . "\n\n";
        }

        if (! empty($request->context)) {
            $preamble .= "### Context\n" . json_encode($request->context, JSON_PRETTY_PRINT) . "\n\n";
        }

        if ($preamble !== '') {
            $parts[] = ['text' => trim($preamble)];
        }

        $userPrompt = $request->prompt;

        if ($request->responseFormat === 'json') {
            $userPrompt .= "\n\nRespond with valid JSON only. No markdown, no prose.";
        }

        $parts[] = ['text' => $userPrompt];

        return [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $request->maxTokens ?? $config['max_tokens'],
                'temperature'     => $request->temperature,
            ],
        ];
    }

    private function assertNoGeminiError(array $body): void
    {
        if (isset($body['error'])) {
            throw new \RuntimeException(
                'Gemini API error: ' . ($body['error']['message'] ?? 'Unknown error')
            );
        }

        if (empty($body['candidates'])) {
            $blockReason = $body['promptFeedback']['blockReason'] ?? 'unknown';
            throw new \RuntimeException(
                "Gemini returned no candidates. Block reason: {$blockReason}"
            );
        }
    }

    private function readSseLine($stream): string
    {
        $line = '';
        while (! $stream->eof()) {
            $char = $stream->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }
        return $line;
    }
}
