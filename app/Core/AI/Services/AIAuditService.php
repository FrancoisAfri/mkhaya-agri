<?php

namespace App\Core\AI\Services;

use App\Shared\DTOs\AgentRequest;
use App\Shared\DTOs\AgentResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AIAuditService
{
    public function record(AgentRequest $request, AgentResponse $response): void
    {
        if (! config('ai.audit.enabled', true)) {
            return;
        }

        try {
            $userId = $request->userId ?: Auth::user()?->uuid;

            if (is_string($userId) && Str::isUuid($userId)) {
                $auditUserId = $userId;
            } else {
                $auditUserId = null;
            }

            DB::table('audit_ai_calls')->insert([
                'id'            => Str::uuid()->toString(),
                'module'        => $request->module,
                'provider'      => $response->provider,
                'prompt_hash'   => hash('sha256', $request->prompt),
                'request_json'  => config('ai.audit.log_prompts', true)
                    ? json_encode($request->toArray())
                    : json_encode(['module' => $request->module, 'redacted' => true]),
                'response_json' => config('ai.audit.log_responses', true)
                    ? json_encode($response->raw)
                    : json_encode(['redacted' => true]),
                'tokens_used'   => $response->totalTokens,
                'duration_ms'   => $response->durationMs,
                'user_id'       => $auditUserId,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI audit logging failed', [
                'module' => $request->module,
                'provider' => $response->provider,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
