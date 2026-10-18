<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An identifier of the wrong shape -- a number where a uuid belongs, text where a number belongs --
 * names nothing, so the answer is 404, not a 500 from Postgres refusing the cast (22P02, 22003).
 * Found by the tenant-isolation sweep (QA-001), which tries every route with foreign identifiers.
 */
class MalformedIdentifierIsNotFound
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $e = $response->exception ?? null;
        if ($e instanceof QueryException && in_array($e->errorInfo[0] ?? $e->getCode(), ['22P02', '22003'], true)) {
            return $request->expectsJson() ? response()->json(['message' => 'Not found.'], 404) : abort(404);
        }

        return $response;
    }
}
