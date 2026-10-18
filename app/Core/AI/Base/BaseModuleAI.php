<?php

namespace App\Core\AI\Base;

use App\Core\AI\Services\HumanizerService;
use App\Core\AI\Contracts\AIAgentInterface;
use App\Core\AI\Registry\AgentRegistry;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

abstract class BaseModuleAI
{
    protected string $module = '';

    protected AIAgentInterface $agent;

    public function __construct(protected AgentRegistry $registry)
    {
        if (empty($this->module)) {
            throw new LogicException(
                static::class . ' must define a $module property.'
            );
        }

        $agent = $registry->for($this->module);
        if (!$agent instanceof AIAgentInterface) {
            throw new RuntimeException(
                "No valid AI agent registered for module '{$this->module}'."
            );
        }
        $this->agent = $agent;
    }

    /**
     * Shorthand to build a request pre‑bound to this module.
     */
    protected function request(): AgentRequest
    {
        return new AgentRequest(
            prompt: '',
            module: $this->module,
        );
    }

    /**
     * Synchronous ask with module context.
     *
     * @param string $prompt      The user prompt
     * @param array  $context     Additional data to provide to the AI
     * @param array  $options     AI options (maxTokens, temperature, etc.)
     * @param string|null $systemPrompt Override the module's default system prompt
     *
     * @throws RuntimeException If the AI call fails
     */
    protected function ask(
        string $prompt,
        array $context = [],
        array $options = [],
        ?string $systemPrompt = null
    ): AgentResponse {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('Prompt cannot be empty.');
        }

        [$agent, $options] = $this->routeAgent($options);

        /*
         * ask() is the prose path — ad copy, outreach messages, summaries, anything somebody
         * reads. The house-style rules go into the system prompt here so every module AI in the
         * platform writes without the tells, rather than each one remembering to ask.
         *
         * askForJson() deliberately does not do this: the rules are about prose, and a JSON
         * payload is not prose.
         */
        // Per call, not per module. A helpdesk class both drafts replies that go to a customer and
        // summarises tickets for a record, and a module-level switch cannot express that — so the
        // module sets the default and the call overrides it.
        $humanize = (bool) ($options['humanize'] ?? $this->humanizes());
        unset($options['humanize']);

        $system = $systemPrompt ?? $this->systemPrompt();
        if ($humanize) {
            $system = app(HumanizerService::class)->applyTo($system);
        }

        $request = $this->request()
            ->withPrompt($prompt)
            ->withContext($context)
            ->withSystemPrompt($system)
            ->withOptions($options); // Assuming AgentRequest has a withOptions() method

        $response = $agent->ask($request);

        if ($response->failed()) {
            throw new RuntimeException(
                "AI call failed: {$response->errorMessage}"
            );
        }

        return $humanize ? $this->humanize($response) : $response;
    }

    /**
     * The module's default. A single call overrides it by passing `humanize` in its options.
     *
     * On for marketing and content, where the output is copy somebody is trying to make read well.
     * Off for the modules whose output is a record — an incident summary, a legal-register note, a
     * disciplinary finding. Rewriting the vocabulary of a record is a different act from polishing
     * an advert, and it should not happen because a marketing feature was switched on.
     */
    protected function humanizes(): bool
    {
        return in_array($this->module, (array) config('ai.humanizer.modules', []), true);
    }

    /** Strips the mechanical tells out of what came back. */
    private function humanize(AgentResponse $response): AgentResponse
    {
        $cleaned = app(HumanizerService::class)->clean($response->content);

        if ($cleaned === $response->content) {
            return $response;
        }

        return new AgentResponse(
            content: $cleaned,
            provider: $response->provider,
            model: $response->model,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            totalTokens: $response->totalTokens,
            durationMs: $response->durationMs,
            success: $response->success,
            errorMessage: $response->errorMessage,
            raw: $response->raw,
        );
    }

    /**
     * Ask expecting a JSON response – returns decoded array.
     *
     * @param string $prompt      The user prompt
     * @param array  $context     Additional context
     * @param array  $options     AI options (maxTokens, temperature, etc.)
     * @param string|null $systemPrompt Override system prompt
     *
     * @return array Decoded JSON data
     *
     * @throws RuntimeException If the AI call fails or response is not valid JSON
     */
    protected function askForJson(
        string $prompt,
        array $context = [],
        array $options = [],
        ?string $systemPrompt = null
    ): array {
        [$agent, $options] = $this->routeAgent($options);

        $request = $this->request()
            ->withPrompt($prompt)
            ->withContext($context)
            ->withSystemPrompt($systemPrompt ?? $this->systemPrompt())
            ->withOptions($options)
            ->expectJson();

        $response = $agent->ask($request);

        if ($response->failed()) {
            throw new RuntimeException(
                "AI call failed: {$response->errorMessage}"
            );
        }

        $data = $response->toArray();
        if (!is_array($data)) {
            throw new RuntimeException('AI response is not a valid JSON object.');
        }

        return $data;
    }

    /**
     * Hybrid routing per call. Pull the routing hints out of $options and pick
     * the agent:
     *   - $options['provider'] — force a specific provider for this one call.
     *   - $options['task']     — route via config('ai.task_overrides') using
     *                            "<module>.<task>" (e.g. summarize → local,
     *                            qualify → openai).
     * With neither hint, the module's default agent is used. The routing keys
     * are stripped before the options reach the request.
     *
     * @return array{0: AIAgentInterface, 1: array}
     */
    protected function routeAgent(array $options): array
    {
        $provider = $options['provider'] ?? null;
        $task     = $options['task'] ?? null;
        unset($options['provider'], $options['task']);

        if ($provider !== null) {
            return [$this->registry->driver($provider), $options];
        }

        if ($task !== null) {
            return [$this->registry->forTask($this->module, $task), $options];
        }

        return [$this->agent, $options];
    }

    /**
     * Override in each module to set the persona and constraints.
     */
    protected function systemPrompt(): string
    {
        return 'You are an AI assistant for an enterprise ERP system. ' .
            'Be concise, factual, and always explain your reasoning. ' .
            'Never fabricate data. If uncertain, say so.';
    }
}
