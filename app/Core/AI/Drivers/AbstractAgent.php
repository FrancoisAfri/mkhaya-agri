<?php

namespace App\Core\AI\Drivers;

use App\Core\AI\Contracts\AIAgentInterface;
use App\Core\AI\Services\AIAuditService;
use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Illuminate\Support\Facades\Log;

abstract class AbstractAgent implements AIAgentInterface
{
    public function __construct(
        protected AIAuditService $auditService,
    ) {}

    /**
     * Driver-specific implementation.
     */
    abstract protected function sendRequest(AgentRequest $request): AgentResponse;

    /**
     * Public entry point — wraps sendRequest with retry + audit.
     */
    public function ask(AgentRequest $request): AgentResponse
    {
        $attempts  = config('ai.retry.attempts', 3);
        $sleepMs   = config('ai.retry.sleep_ms', 500);
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = $this->sendRequest($request);

                $this->audit($request, $response);

                return $response;

            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning("AI attempt {$i}/{$attempts} failed", [
                    'provider' => $this->providerName(),
                    'module'   => $request->module,
                    'error'    => $e->getMessage(),
                ]);

                if ($i < $attempts) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $failure = AgentResponse::failure($lastError->getMessage(), $this->providerName());
        $this->audit($request, $failure);

        return $failure;
    }

    protected function audit(AgentRequest $request, AgentResponse $response): void
    {
        // Meter every driver-system AI call against the company's monthly allowance.
        // Previously only ERPCopilotService logged usage, so all module AI (procurement
        // invoice extraction, spend narratives, perf-appraisal, etc.) was invisible to
        // the quota — a tenant could run up cost with nothing counted or reported.
        $this->meterUsage($request, $response);

        if (! config('ai.audit.enabled', true)) {
            return;
        }

        $this->auditService->record($request, $response);
    }

    /** Record this call's token usage against the active company (drives the AI quota). */
    private function meterUsage(AgentRequest $request, AgentResponse $response): void
    {
        try {
            \App\Modules\HR\Leave\Models\AiUsageLog::create([
                'user_id'           => \Illuminate\Support\Facades\Auth::id(),
                'company_id'        => app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id(),
                'feature'           => 'module:' . $request->module,
                'model_used'        => $response->model,
                'prompt_tokens'     => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'total_tokens'      => $response->totalTokens,
                'input_summary'     => \Illuminate\Support\Str::limit($request->prompt, 180),
                'latency_ms'        => $response->durationMs,
                'success'           => $response->success,
                'error_message'     => $response->errorMessage,
            ]);
        } catch (\Throwable) {
            // Metering must never break an AI feature.
        }
    }

    protected function buildMessages(AgentRequest $request): array
    {
        $messages = [];

        if (! empty($request->context)) {
            $contextBlock = "### Context\n" . json_encode($request->context, JSON_PRETTY_PRINT);
            $messages[] = ['role' => 'user', 'content' => $contextBlock];
            $messages[] = ['role' => 'assistant', 'content' => 'Context received.'];
        }

        foreach ($request->history as $turn) {
            if (isset($turn['role'], $turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        return $messages;
    }
}
