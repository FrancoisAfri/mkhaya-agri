<?php

namespace App\Core\AI\Contracts;

use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Generator;

interface AIAgentInterface
{
    /**
     * Send a synchronous request to the AI provider.
     */
    public function ask(AgentRequest $request): AgentResponse;

    /**
     * Stream a response token-by-token (for real-time UI).
     */
    public function stream(AgentRequest $request): Generator;

    /**
     * Return the provider identifier — 'claude', 'openai', 'gemini', 'mock'.
     */
    public function providerName(): string;
}
