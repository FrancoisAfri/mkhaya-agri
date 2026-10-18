<?php

namespace App\Modules\Operations\ProjectManagement\Http\Middleware;

use App\Modules\Operations\ProjectManagement\Support\Api\ProblemDetails;
use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a mutating PM API call safe to retry (§4, PM-1003).
 *
 * A client sends `Idempotency-Key`. The first request runs and its response is stored; any repeat
 * of the same key returns that stored response without executing anything again. This is what lets
 * a field device retry blindly after a timeout — it cannot know whether the server processed the
 * first attempt, and it should not have to.
 *
 * Applied as `pm.idempotent`, or `pm.idempotent:required` on the sync endpoints where a missing
 * key is a client error rather than a choice.
 */
class EnforceIdempotency
{
    /** Verbs that change something. GET/HEAD are naturally repeatable. */
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! in_array($request->method(), self::MUTATING, true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            if ($mode === 'required') {
                return ProblemDetails::make(
                    status: 400,
                    title: 'Idempotency-Key required',
                    detail: 'This endpoint requires an Idempotency-Key header so a retry cannot duplicate the record.',
                    request: $request,
                );
            }

            return $next($request);
        }

        $companyId = $this->current->id();
        $fingerprint = hash('sha256', $request->method() . '|' . $request->path() . '|' . $request->getContent());

        $existing = DB::table('pm_idempotency_keys')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->first();

        if ($existing) {
            // Same key, different body: the client has reused a key for a different call. Replaying
            // the first response would be actively wrong, so say so instead.
            if (! hash_equals($existing->request_hash, $fingerprint)) {
                return ProblemDetails::make(
                    status: 422,
                    title: 'Idempotency-Key reused',
                    detail: 'This Idempotency-Key was already used for a different request body.',
                    request: $request,
                );
            }

            if ($existing->completed_at !== null) {
                return $this->replay($existing);
            }

            // Still in flight — two retries raced. The client should back off rather than get a
            // half-finished answer.
            return ProblemDetails::make(
                status: 409,
                title: 'Request in progress',
                detail: 'A request with this Idempotency-Key is still being processed. Retry shortly.',
                request: $request,
            );
        }

        // Claim the key before doing the work. The unique index is the real guard: if a concurrent
        // retry inserted first, we lose the race and treat it as in-flight.
        try {
            DB::table('pm_idempotency_keys')->insert([
                'company_id'   => $companyId,
                'key'          => $key,
                'user_id'      => $request->user()?->id,
                'method'       => $request->method(),
                'path'         => $request->path(),
                'request_hash' => $fingerprint,
                'locked_at'    => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            return ProblemDetails::make(
                status: 409,
                title: 'Request in progress',
                detail: 'A request with this Idempotency-Key is already being processed.',
                request: $request,
            );
        }

        $response = $next($request);

        $this->remember($companyId, $key, $response);

        return $response;
    }

    /**
     * Store the outcome so the next retry can be answered without re-running anything.
     *
     * Only successful responses are stored. A failure is not an outcome worth pinning to a key —
     * the client should be able to correct the problem and retry with the same key.
     */
    private function remember(?int $companyId, string $key, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 400) {
            DB::table('pm_idempotency_keys')
                ->where('company_id', $companyId)->where('key', $key)
                ->delete();

            return;
        }

        DB::table('pm_idempotency_keys')
            ->where('company_id', $companyId)->where('key', $key)
            ->update([
                'response_status' => $status,
                'response_body'   => $response->getContent(),
                'completed_at'    => now(),
                'locked_at'       => null,
                'updated_at'      => now(),
            ]);
    }

    private function replay(object $record): Response
    {
        return response(
            $record->response_body ?? '',
            $record->response_status ?? 200,
            [
                'Content-Type'        => 'application/json',
                // Tells the client this is a replay, not a fresh execution — useful when debugging
                // a device that thinks it created two of something.
                'Idempotent-Replay'   => 'true',
            ]
        );
    }
}
