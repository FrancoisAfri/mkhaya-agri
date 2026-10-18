<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Middleware;

use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retriable API commands (WMS-172). A request carrying `Idempotency-Key` is done once: the same key
 * with the same body answers with the first response (marked `Idempotent-Replay: true`), the same
 * key with a different body is refused (422), and one still in progress answers 409. A refused or
 * failed request is not remembered, so it can be corrected and sent again under the same key.
 * Keys are per company and per user and kept for 24 hours.
 */
class Idempotent
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $key)) {
            return response()->json(['message' => 'Idempotency-Key must be 8 to 120 letters, digits or . _ : -'], 400);
        }
        $company = $this->current->id();
        $user = (int) $request->user()->id;
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        DB::table('wms_idempotency_keys')->where('created_at', '<', now()->subDay())->where('company_id', $company)->delete();

        $seen = DB::table('wms_idempotency_keys')->where('company_id', $company)->where('user_id', $user)->where('key', $key)->first();
        if ($seen) {
            return $this->answerSeen($seen, $hash);
        }
        try {
            // A savepoint: a lost race must not abort an enclosing transaction (Postgres would).
            $id = DB::transaction(fn () => DB::table('wms_idempotency_keys')->insertGetId(['company_id' => $company, 'user_id' => $user, 'key' => $key, 'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 300), 'request_hash' => $hash, 'created_at' => now()]));
        } catch (QueryException) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('wms_idempotency_keys')->where('id', $id)->delete();
            throw $e;
        }
        if ($response->getStatusCode() >= 400) {
            DB::table('wms_idempotency_keys')->where('id', $id)->delete();
        } else {
            DB::table('wms_idempotency_keys')->where('id', $id)->update(['response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(), 'completed_at' => now()]);
        }

        return $response;
    }

    private function answerSeen(object $seen, string $hash): Response
    {
        if (! hash_equals($seen->request_hash, $hash)) {
            return response()->json(['message' => 'This Idempotency-Key was used for a different request.'], 422);
        }
        if ($seen->completed_at === null) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        return response((string) $seen->response_body, (int) $seen->response_status, ['Content-Type' => 'application/json', 'Idempotent-Replay' => 'true']);
    }
}
