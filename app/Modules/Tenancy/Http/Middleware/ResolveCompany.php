<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company for the request:
 *  - a normal user → their own company (company_id);
 *  - a platform admin → the company they're impersonating (session), else none
 *    (no scope → sees all, for the super-admin portal).
 *
 * Also blocks access to suspended companies and expired trials.
 */
class ResolveCompany
{
    public function __construct(private CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Platform admins: optionally impersonating a company via the super-admin portal.
        if ($user->is_platform_admin || ($user->is_master_admin ?? false)) {
            $impersonatedId = $request->session()->get('impersonate_company_id');

            if ($impersonatedId) {
                $this->current->set((int) $impersonatedId, impersonating: true);

                // A company converted to a reseller is kept only as a record until its purge date:
                // administrators may look, not change. Platform routes (stop viewing, billing) and
                // signing out still work.
                if (! $request->isMethodSafe() && ! $request->routeIs('tenancy.*', 'logout', 'auth.*')
                    && Company::query()->whereKey($impersonatedId)->where('status', 'archived')->exists()) {
                    abort(403, 'This company was converted to a reseller; its archived data is read only.');
                }
            }

            // Stamped either way, and this is the important part. Setting CurrentCompany alone
            // scopes the queries the application remembers to scope; it does nothing to row level
            // security, which is the layer that holds when a query forgets. Without this an
            // administrator viewing a client company still saw every other tenant's rows through
            // any query that was not itself filtered — the isolation looked enforced and was not.
            //
            // The empty case matters too: `set_config` is session-level, so on a pooled connection
            // an administrator who is *not* impersonating would otherwise inherit whatever tenant
            // the previous request left behind.
            $this->stampDbSession($impersonatedId ? (int) $impersonatedId : null);

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

            return $next($request);
        }

        // A user with no company of their own: cleared rather than left as whatever the last
        // request on this connection happened to set.
        $this->stampDbSession(null);

        return $next($request);
    }

    /**
     * Set the tenant on the DB session so Postgres row-level security policies can
     * enforce isolation independently of the app-level global scope. Harmless when
     * no RLS policy references it. Silently ignored on non-Postgres drivers.
     */
    private function stampDbSession(?int $companyId): void
    {
        // In the test suite we leave the RLS session var UNSET so the existing
        // (single-tenant) feature tests aren't filtered by row-level security. The
        // app-level CompanyScope still applies, and the dedicated tenancy tests set
        // `app.company_id` themselves to verify RLS isolation explicitly. Production
        // is unaffected.
        //
        // A test that is specifically about isolation can opt back in with
        // `config(['tenancy.stamp_rls_in_tests' => true])`, which is the only way to prove
        // through a real request that the middleware stamps at all — the bug this guard
        // hid was impersonation setting the app-level company and never reaching here.
        if (app()->environment('testing') && ! config('tenancy.stamp_rls_in_tests', false)) {
            return;
        }
        try {
            DB::statement("SELECT set_config('app.company_id', ?, false)", [$companyId === null ? '' : (string) $companyId]);
        } catch (\Throwable) {
            // best-effort; the app-level CompanyScope remains the primary guard
        }
    }
}
