<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Services\AIAuditService;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Generator;
use Illuminate\Support\Facades\File;

class MockAgent extends AbstractAgent
{
    private array $overrides = [];

    public function __construct(AIAuditService $auditService)
    {
        parent::__construct($auditService);
    }

    public function providerName(): string
    {
        return 'mock';
    }

    // -------------------------------------------------------------------------
    // Override responses at runtime — used in individual tests
    // -------------------------------------------------------------------------

    /**
     * Set a specific response for a module in this test.
     *
     * MockAgent::respondWith('payroll', ['anomalies' => []]);
     */
    public function respondWith(string $module, array|string $content): self
    {
        $this->overrides[$module] = is_array($content)
            ? json_encode($content)
            : $content;

        return $this;
    }

    public function clearOverrides(): self
    {
        $this->overrides = [];
        return $this;
    }

    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    protected function sendRequest(AgentRequest $request): AgentResponse
    {
        $content = $this->resolveContent($request);

        return new AgentResponse(
            content          : $content,
            provider         : 'mock',
            model            : 'mock-1.0',
            promptTokens     : str_word_count($request->prompt),
            completionTokens : str_word_count($content),
            totalTokens      : str_word_count($request->prompt) + str_word_count($content),
            durationMs       : 1,
            success          : true,
            raw              : ['mock' => true, 'module' => $request->module],
        );
    }

    public function stream(AgentRequest $request): Generator
    {
        $content = $this->resolveContent($request);
        $words   = explode(' ', $content);

        foreach ($words as $word) {
            yield $word . ' ';
        }
    }

    // -------------------------------------------------------------------------
    // Fixture resolution — override > module fixture file > default
    // -------------------------------------------------------------------------

    private function resolveContent(AgentRequest $request): string
    {
        // 1. Runtime override set in a test
        if (isset($this->overrides[$request->module])) {
            return $this->overrides[$request->module];
        }

        // 2. Fixture file: tests/Fixtures/AI/{module}.json
        $fixturePath = $this->fixturePath($request->module . '.json');

        if (File::exists($fixturePath)) {
            return File::get($fixturePath);
        }

        // 3. Default fallback
        return $this->defaultFixture($request->module);
    }

    private function fixturePath(string $filename): string
    {
        $base = config('ai.providers.mock.fixture_path', base_path('tests/Fixtures/AI'));
        return rtrim($base, '/') . '/' . $filename;
    }

    private function defaultFixture(string $module): string
    {
        $defaults = [
            'payroll' => json_encode([
                'anomalies' => [],
                'summary'   => 'No anomalies detected in this payroll run.',
                'risk'      => 'low',
            ]),

            'hr' => json_encode([
                'risk_level'      => 'low',
                'risk_score'      => 10,
                'reasons'         => ['No conflicts detected in team calendar.'],
                'recommendation'  => 'approve',
                'conditions'      => [],
                'coverage_needed' => false,
            ]),

            'fleet' => json_encode([
                'anomalies'    => [],
                'health_score' => 85,
                'summary'      => 'Vehicle is operating within normal parameters.',
            ]),

            'recruitment' => json_encode([
                'full_name'          => 'John Doe',
                'years_experience'   => 5,
                'skills'             => ['PHP', 'Laravel', 'PostgreSQL'],
                'qualifications'     => [],
                'employment_history' => [],
                'gap_periods'        => [],
                'red_flags'          => [],
                'job_fit_score'      => 72,
                'fit_summary'        => 'Candidate meets core requirements.',
            ]),
        ];

        return $defaults[$module]
            ?? json_encode(['response' => "Mock response for module: {$module}"]);
    }
}
