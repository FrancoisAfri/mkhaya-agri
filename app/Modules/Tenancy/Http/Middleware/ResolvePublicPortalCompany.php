<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the tenant for PUBLIC (unauthenticated) portals — e.g. the anonymous
 * whistleblower portal — where ResolveCompany can't help (no user) and there is
 * no bound entity carrying a company_id. Without this, every insert lands with
 * company_id = NULL (invisible to the tenant) and reads leak across tenants.
 *
 * Resolution order:
 *   1. `?c={slug}` query param — each company shares its own portal link.
 *   2. `company` route parameter (slug), if the route defines one.
 *   3. Session (sticky within the visitor's portal flow, so follow-up POSTs and
 *      status pages keep the tenant without re-carrying the slug).
 *   4. Sole active company (single-tenant / self-hosted installs).
 * If none resolve, the portal 404s rather than writing orphaned data.
 */
class ResolvePublicPortalCompany
{
    public function handle(Request $request, Closure $next)
    {
        // Same convention as ResolveCompany::stampDbSession — feature tests run
        // with the tenancy DB session bypassed so untenanted fixtures stay visible.
        if (app()->environment('testing')) {
            return $next($request);
        }

        $slug = $request->query('c') ?: $request->route('company');

        $company = null;
        if (is_string($slug) && $slug !== '') {
            $company = $this->serviceable(Company::where('slug', $slug))->first();
            abort_unless($company, 404, 'Unknown portal link.');
        }

        if (! $company && ($sessionId = $request->session()->get('portal_company_id'))) {
            $company = $this->serviceable(Company::whereKey($sessionId))->first();
        }

        if (! $company) {
            // Single-tenant fallback: exactly one serviceable company. With ZERO
            // companies (fresh install / test env) there is nothing to isolate —
            // proceed untenanted rather than blocking the portal.
            $companies = $this->serviceable(Company::query())->limit(2)->get();
            if ($companies->isEmpty()) {
                return $next($request);
            }
            $company = $companies->count() === 1 ? $companies->first() : null;
        }

        abort_unless($company, 404, 'This portal link is missing its company identifier.');

        $request->session()->put('portal_company_id', $company->id);
        app(TenantContext::class)->apply((int) $company->id);

        return $next($request);
    }

    /**
     * Companies whose public portals should answer.
     *
     * `active` and `trial` are SaaS subscription states. A self-hosted customer has no
     * subscription to be in one of, so their single company's status is whatever the install left
     * behind — and filtering on it here turned every public portal on a standalone installation
     * into a 404: supplier onboarding links, whistleblower reports, survey invitations. The
     * licence, not a billing word, is what says whether a self-hosted instance is entitled.
     */
    private function serviceable($query)
    {
        return $query->when(
            ! \App\Modules\Tenancy\Services\Edition::isSelfHosted(),
            fn ($q) => $q->whereIn('status', ['active', 'trial'])
        );
    }
}
