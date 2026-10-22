<?php

namespace App\Modules\Agri\Http\Middleware;

use App\Modules\Agri\Api\ApiProblem;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A POST, PATCH or DELETE carrying `Idempotency-Key` is done once per API client. The same key with
 * the same body answers with the first response (marked `Idempotent-Replay: true`); the same key
 * with a different body is refused (422); one still running answers 409. A refused or failed request
 * is not remembered, so it can be corrected and sent again under the same key. Kept 24 hours.
 */
class AgriApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $client = $request->attributes->get('agri_api_client');
        if ($key === null || $request->isMethodSafe() || ! $client) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $key)) {
            return ApiProblem::make(400, 'Bad request', 'Idempotency-Key must be 8 to 120 letters, digits or . _ : -', $request);
        }
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $hours = (int) config('agri.api.idempotency_hours', 24);
        $seen = DB::table('agri_api_idempotency')->where('company_id', $client->company_id)->where('client_id', $client->id)->where('key', $key)->first();
        if ($seen && $seen->created_at < now()->subHours($hours)->toDateTimeString()) {
            DB::table('agri_api_idempotency')->where('id', $seen->id)->delete();
            $seen = null;
        }
        if ($seen) {
            if (! hash_equals($seen->request_hash, $hash)) {
                return ApiProblem::make(422, 'Unprocessable', 'This Idempotency-Key was used for a different request.', $request, [], 'idempotency-key-reused');
            }
            if ($seen->completed_at === null) {
                return ApiProblem::make(409, 'Conflict', 'A request with this Idempotency-Key is still being processed.', $request);
            }

            return response((string) $seen->response_body, (int) $seen->response_status, ['Content-Type' => 'application/json', 'Idempotent-Replay' => 'true']);
        }
        try {
            // A savepoint: a lost race must not abort an enclosing transaction.
            $id = DB::transaction(fn () => DB::table('agri_api_idempotency')->insertGetId(['company_id' => $client->company_id, 'client_id' => $client->id, 'key' => $key,
                'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 300), 'request_hash' => $hash, 'created_at' => now()]));
        } catch (QueryException) {
            return ApiProblem::make(409, 'Conflict', 'A request with this Idempotency-Key is still being processed.', $request);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('agri_api_idempotency')->where('id', $id)->delete();
            throw $e;
        }
        if ($response->getStatusCode() >= 400) {
            DB::table('agri_api_idempotency')->where('id', $id)->delete();
        } else {
            DB::table('agri_api_idempotency')->where('id', $id)->update(['response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(), 'completed_at' => now()]);
        }

        return $response;
    }
}
