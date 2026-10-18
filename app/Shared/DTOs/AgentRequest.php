<?php

namespace App\Shared\DTOs;

class AgentRequest
{
    public function __construct(
        public readonly string  $prompt,
        public readonly string  $module,
        public readonly array   $context        = [],
        public readonly string  $systemPrompt   = '',
        public readonly float   $temperature    = 0.3,
        public readonly int     $maxTokens      = 2048,
        public readonly string  $responseFormat = 'text',  // 'text' | 'json'
        public readonly ?string $userId         = null,
        public readonly array   $history        = [],      // [{role, content}, ...]
    ) {}

    public static function make(): self
    {
        return new self(prompt: '', module: '');
    }

    public function withPrompt(string $prompt): self
    {
        return new self(
            prompt        : $prompt,
            module        : $this->module,
            context       : $this->context,
            systemPrompt  : $this->systemPrompt,
            temperature   : $this->temperature,
            maxTokens     : $this->maxTokens,
            responseFormat: $this->responseFormat,
            userId        : $this->userId,
            history       : $this->history,
        );
    }

    public function withContext(array $context): self
    {
        return new self(
            prompt        : $this->prompt,
            module        : $this->module,
            context       : $context,
            systemPrompt  : $this->systemPrompt,
            temperature   : $this->temperature,
            maxTokens     : $this->maxTokens,
            responseFormat: $this->responseFormat,
            userId        : $this->userId,
            history       : $this->history,
        );
    }

    public function withModule(string $module): self
    {
        return new self(
            prompt        : $this->prompt,
            module        : $module,
            context       : $this->context,
            systemPrompt  : $this->systemPrompt,
            temperature   : $this->temperature,
            maxTokens     : $this->maxTokens,
            responseFormat: $this->responseFormat,
            userId        : $this->userId,
            history       : $this->history,
        );
    }

    public function withOptions(array $options): self
    {
        return new self(
            prompt        : $this->prompt,
            module        : $this->module,
            context       : $this->context,
            systemPrompt  : $this->systemPrompt,
            temperature   : $options['temperature'] ?? $this->temperature,
            maxTokens     : $options['maxTokens']   ?? $this->maxTokens,
            responseFormat: $this->responseFormat,
            userId        : $this->userId,
            history       : $this->history,
        );
    }

    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            prompt        : $this->prompt,
            module        : $this->module,
            context       : $this->context,
            systemPrompt  : $systemPrompt,
            temperature   : $this->temperature,
            maxTokens     : $this->maxTokens,
            responseFormat: $this->responseFormat,
            userId        : $this->userId,
            history       : $this->history,
        );
    }

    public function withHistory(array $history): self
    {
        return new self(
            prompt        : $this->prompt,
            module        : $this->module,
            context       : $this->context,
            systemPrompt  : $this->systemPrompt,
            temperature   : $this->temperature,
            maxTokens     : $this->maxTokens,
            responseFormat: $this->responseFormat,
            userId        : $this->userId,
            history       : $history,
        );
    }

    public function expectJson(): self
    {
        return new self(
            prompt        : $this->prompt,
            module        : $this->module,
            context       : $this->context,
            systemPrompt  : $this->systemPrompt,
            temperature   : $this->temperature,
            maxTokens     : $this->maxTokens,
            responseFormat: 'json',
            userId        : $this->userId,
            history       : $this->history,
        );
    }

    public function toArray(): array
    {
        return [
            'prompt'         => $this->prompt,
            'module'         => $this->module,
            'context'        => $this->context,
            'system_prompt'  => $this->systemPrompt,
            'temperature'    => $this->temperature,
            'max_tokens'     => $this->maxTokens,
            'response_format'=> $this->responseFormat,
            'user_id'        => $this->userId,
            'history'        => $this->history,
        ];
    }
}
