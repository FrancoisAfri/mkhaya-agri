<?php

namespace App\Modules\Operations\ProjectManagement\Http\Middleware;

use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any PM API request that has not resolved to a tenant (PM-1010).
 *
 * ResolveCompanyFromToken sets the tenant when there is one. This is the second half of that
 * contract: if it did not, the request stops here rather than proceeding unscoped. Without it a
 * platform admin — who legitimately has no company — would read every tenant's projects through
 * the API, which is precisely the outcome the ticket exists to prevent.
 *
 * Platform administration happens through the web console, where the operator chooses a company
 * deliberately. The API is a tenant surface, and this makes that explicit rather than implied.
 */
class RequireTenantContext
{
    public function __construct(private CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->current->isSet()) {
            abort(403, 'This endpoint requires a tenant context. Authenticate as a user belonging to a company.');
        }

        return $next($request);
    }
}
