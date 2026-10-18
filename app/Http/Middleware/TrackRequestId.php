<?php

namespace App\Http\Middleware;

use App\Modules\Operations\ProjectManagement\Services\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id and echoes it back on the response.
 *
 * The PM audit log stamps this id on each row (PM-1024), so a support question — "what else did
 * that save change?" — is answered by filtering on one value instead of correlating timestamps.
 * An inbound `X-Request-Id` from a gateway or a mobile client is adopted rather than replaced, so
 * a trace that started upstream stays one trace.
 */
class TrackRequestId
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->adopt($request->header('X-Request-Id'));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $this->context->requestId());

        return $response;
    }
}
