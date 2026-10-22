<?php

namespace App\Modules\Agri\Http\Middleware;

use App\Modules\Agri\Api\ApiProblem;
use App\Modules\Agri\Api\ApiTokens;
use App\Modules\Agri\Models\ApiCall;
use App\Modules\Agri\Models\ApiClient;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door of the public farm API, after Sanctum has found the token.
 *
 * Only a farm API client's token gets in — not a session, not a token made for another module — and
 * only while the client is live, its organisation is the one the request was pinned to and that
 * organisation has the farm module. Each client has its own per-minute limit. Every call that gets
 * this far is logged with who made it, what it asked for and what it was answered.
 */
class AgriApiGuard
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $token = $request->user()?->currentAccessToken();
        $client = $token instanceof PersonalAccessToken
            ? ApiClient::withoutGlobalScopes()->where('token_id', $token->id)->first()
            : null;
        if (! $client || ! $client->isLive()) {
            return ApiProblem::make(401, 'Unauthenticated', 'This is not a live Mkhaya Agri API token.', $request);
        }
        if (! $this->current->isSet() || (int) $this->current->id() !== (int) $client->company_id || (int) $request->user()->company_id !== (int) $client->company_id) {
            return $this->log($request, $client, $started, ApiProblem::make(403, 'Forbidden', 'This token does not belong to an organisation.', $request));
        }
        if ((int) $request->user()->status === 0) {
            return $this->log($request, $client, $started, ApiProblem::make(403, 'Forbidden', 'This API client has been switched off.', $request));
        }
        if (! app(CompanyEntitlements::class)->currentAllowsModule('agri')) {
            return $this->log($request, $client, $started, ApiProblem::make(403, 'Forbidden', 'Farm management is not in this organisation\'s plan.', $request));
        }

        $key = 'agri-api:'.$client->id;
        $limit = max(1, (int) $client->rate_per_minute);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $wait = RateLimiter::availableIn($key);

            return $this->log($request, $client, $started, ApiProblem::make(429, 'Too many requests', "This token may make {$limit} calls a minute; try again in {$wait} s.", $request)
                ->withHeaders(['Retry-After' => $wait, 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0]));
        }
        RateLimiter::hit($key, 60);

        $request->attributes->set('agri_api_client', $client);
        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        if (random_int(1, 500) === 1) {
            app(ApiTokens::class)->prune((int) $client->company_id);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Rendered by the exception handler as a problem document; logged here with its status.
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode()
                : ($e instanceof \Illuminate\Validation\ValidationException ? 422 : ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500));
            $this->record($request, $client, $started, $status, false, mb_strimwidth($e->getMessage(), 0, 300));
            throw $e;
        }
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

        return $this->log($request, $client, $started, $response);
    }

    private function log(Request $request, ApiClient $client, float $started, Response $response): Response
    {
        $error = null;
        if ($response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            $error = is_array($body) ? mb_strimwidth((string) ($body['detail'] ?? $body['title'] ?? $body['message'] ?? ''), 0, 300) : null;
        }
        $this->record($request, $client, $started, $response->getStatusCode(), $response->headers->has('Idempotent-Replay'), $error);

        return $response;
    }

    private function record(Request $request, ApiClient $client, float $started, int $status, bool $replayed, ?string $error): void
    {
        try {
            ApiCall::withoutGlobalScopes()->create(['company_id' => $client->company_id, 'client_id' => $client->id, 'user_id' => $client->service_user_id,
                'method' => $request->method(), 'path' => mb_strimwidth('/'.$request->path().($request->getQueryString() ? '?'.$request->getQueryString() : ''), 0, 300),
                'route' => $request->route()?->getName(), 'status' => $status, 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'ip' => $request->ip(), 'replayed' => $replayed, 'error' => $error ?: null, 'created_at' => now()]);
        } catch (\Throwable) {
            // The log never stops the call it is about.
        }
    }
}
