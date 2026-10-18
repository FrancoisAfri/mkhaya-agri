<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API's counterpart to ResolveCompany: pins the request to the authenticated tenant so
 * CompanyScope and row-level security apply to API traffic the way they do to web traffic.
 *
 * Why this exists: the `api` middleware group was `throttle` + `SubstituteBindings` only, so an
 * API request carried no tenant at all. With no CurrentCompany the global CompanyScope does not
 * filter, and because the RLS policy reads `app.company_id IS NULL OR company_id = app.company_id`,
 * an unset session variable *permits every row*. API endpoints were therefore relying on each
 * controller to filter by hand — and the existing HelpDesk mobile controller does not.
 *
 * The tenant comes from the authenticated user behind the bearer token, never from a request
 * parameter: a client cannot ask to be another tenant.
 *
 * Requests with no authenticated user pass through untouched — public endpoints, login, and the
 * service-to-service endpoints that authenticate with their own shared token rather than Sanctum.
 * Those either expose nothing tenant-owned or do their own checks; making this middleware reject
 * them would break them without making anything safer.
 */
class ResolveCompanyFromToken
{
    public function __construct(private CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum first (bearer tokens, and stateful SPA requests), then whatever guard the route
        // itself authenticated with.
        $user = $request->user('sanctum') ?? $request->user();

        if (! $user) {
            return $next($request);
        }

        // Platform admins have no company of their own. There is no session on an API request, so
        // there is no impersonation to read either: they simply run unscoped, exactly as they do on
        // the web. Routes that must never be served without a tenant say so themselves — see
        // RequireTenantContext, which the PM API applies.
        if ($user->is_platform_admin || ($user->is_master_admin ?? false)) {
            return $next($request);
        }

        if ($user->company_id) {
            $company = Company::find($user->company_id);

            if ($company && $company->isArchived()) {
                abort(403, 'This company account has been closed. Please contact your administrator.');
            }
            if ($company && $company->isSuspended()) {
                abort(403, 'This account is suspended. Please contact your administrator.');
            }
            if ($company && $company->trialExpired()) {
                abort(403, 'Your free trial has ended. Please contact Mkhaya to activate your subscription.');
            }

            $this->current->set((int) $user->company_id);
            $this->stampDbSession((int) $user->company_id);
        }

        return $next($request);
    }

    /**
     * Set the tenant on the database session so row-level security enforces isolation independently
     * of the ORM scope.
     *
     * Skipped under `testing` for the same reason ResolveCompany skips it: the existing
     * single-tenant feature suites are not written against RLS, and leaving the variable unset
     * keeps them working while CompanyScope still isolates. Tests that assert RLS specifically set
     * `app.company_id` themselves.
     */
    private function stampDbSession(int $companyId): void
    {
        if (app()->environment('testing')) {
            return;
        }

        try {
            DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
        } catch (\Throwable) {
            // Best-effort: the app-level CompanyScope remains the primary guard.
        }
    }
}
