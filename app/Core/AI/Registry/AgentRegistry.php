<?php

namespace App\Core\AI\Registry;

use App\Core\AI\Contracts\AIAgentInterface;
use App\Core\AI\Drivers\ChainAgent;
use App\Core\AI\Drivers\ClaudeAgent;
use App\Core\AI\Drivers\ClaudeHaikuAgent;
use App\Core\AI\Drivers\DeepSeekAgent;
use App\Core\AI\Drivers\GeminiAgent;
use App\Core\AI\Drivers\LocalAgent;
use App\Core\AI\Drivers\MockAgent;
use App\Core\AI\Drivers\OpenAIAgent;
use InvalidArgumentException;

class AgentRegistry
{
    private array $resolved = [];

    private array $driverMap = [
        // Free-first waterfall across ~19 providers → paid OpenAI only as last resort.
        'chain'         => ChainAgent::class,
        'claude'        => ClaudeAgent::class,
        'claude_haiku'  => ClaudeHaikuAgent::class,
        'openai'        => OpenAIAgent::class,
        'gemini'        => GeminiAgent::class,
        'deepseek'      => DeepSeekAgent::class,
        'local'         => LocalAgent::class,
        'mock'          => MockAgent::class,
    ];

    public function for(string $module): AIAgentInterface
    {
        return $this->resolve($this->providerFor($module));
    }

    /**
     * Task-aware resolution (hybrid routing). Checks "ai.task_overrides" for a
     * "<module>.<task>" entry first, then falls back to the module default.
     * Routine tasks land on the cheap 'local' tier; hard ones escalate.
     */
    public function forTask(string $module, ?string $task): AIAgentInterface
    {
        if ($task !== null && $task !== '') {
            $taskOverrides = config('ai.task_overrides', []);
            $provider = $taskOverrides["{$module}.{$task}"] ?? null;

            if ($provider !== null) {
                return $this->resolve($provider);
            }
        }

        return $this->for($module);
    }

    private function providerFor(string $module): string
    {
        $overrides = config('ai.overrides', []);

        return $overrides[$module] ?? config('ai.provider', 'claude');
    }

    /** Resolve a provider name to a cached driver, falling back if unconfigured. */
    private function resolve(string $provider): AIAgentInterface
    {
        if (! $this->isProviderConfigured($provider)) {
            $provider = $this->firstAvailableProvider();
        }

        if (! isset($this->resolved[$provider])) {
            $this->resolved[$provider] = $this->make($provider);
        }

        return $this->resolved[$provider];
    }

    public function driver(string $provider): AIAgentInterface
    {
        return $this->make($provider);
    }

    public function supportedProviders(): array
    {
        return array_keys($this->driverMap);
    }

    private function make(string $provider): AIAgentInterface
    {
        if (! array_key_exists($provider, $this->driverMap)) {
            throw new InvalidArgumentException(
                "AI provider [{$provider}] is not registered. " .
                'Supported: ' . implode(', ', array_keys($this->driverMap))
            );
        }

        return app($this->driverMap[$provider]);
    }

    private function firstAvailableProvider(): string
    {
        foreach (['chain', 'openai', 'claude', 'gemini', 'deepseek', 'local', 'mock'] as $provider) {
            if ($this->isProviderConfigured($provider)) {
                return $provider;
            }
        }

        return 'mock';
    }

    private function isProviderConfigured(string $provider): bool
    {
        if (! array_key_exists($provider, $this->driverMap)) {
            return false;
        }

        if ($provider === 'mock') {
            return true;
        }

        // The chain self-manages its providers and always has a graceful fallback,
        // so it is considered configured as long as at least one chat provider has
        // a key (otherwise fall through to another driver rather than dead-ending).
        if ($provider === 'chain') {
            foreach ((array) config('ai.chat.providers', []) as $p) {
                if (! empty($p['key']) || ! empty($p['keyless'])) {
                    return true;
                }
            }

            return false;
        }

        $config = config("ai.providers.{$provider}", []);

        // The 'local' tier may run keyless (e.g. on-prem Ollama). It counts as
        // configured as long as it has been deliberately pointed somewhere —
        // i.e. either a key OR a non-default base URL is set.
        if ($provider === 'local') {
            $hasKey     = is_string($config['api_key'] ?? null) && trim($config['api_key']) !== '';
            $hasBaseUrl = is_string($config['base_url'] ?? null) && trim($config['base_url']) !== '';

            return $hasKey || ($hasBaseUrl && env('LOCAL_AI_ENABLED', false));
        }

        $apiKey = $config['api_key'] ?? $config['key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '';
    }
}
