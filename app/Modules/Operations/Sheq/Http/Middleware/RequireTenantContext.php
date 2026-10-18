<?php

namespace App\Modules\Operations\Sheq\Http\Middleware;

use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any SHEQ API request that has not resolved to a tenant.
 *
 * The api group resolves the company from the token's user. This is the other half of that
 * contract: if it did not resolve one, the request stops here rather than continuing unscoped.
 * A platform admin legitimately has no company, and an unset `app.company_id` makes the RLS policy
 * permit every row — so without this a platform token would read every tenant's incidents.
 *
 * Deliberately a 403 with an explanation rather than an empty result set: a device that received
 * "no incidents" would treat it as a clean sync and overwrite its local store with nothing.
 */
class RequireTenantContext
{
    public function __construct(private CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->current->isSet()) {
            abort(403, 'This endpoint requires a tenant context. Authenticate as a user belonging to a company.');
        }

        return $next($request);
    }
}
