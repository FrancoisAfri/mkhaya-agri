<?php

namespace App\Modules\Operations\Sheq\Support;

use App\Modules\Operations\Sheq\Security\SiteEntitlement;
use Illuminate\Http\Request;

/**
 * Read a site from a request only if this user may open it (SEC-2, TC-SEC-02).
 *
 * Every SHEQ register took `$request->integer('site_id') ?: null` and used it. That is a filter,
 * not an entitlement: a user with module access could read any site's permits, attendance or
 * incidents by changing a query parameter, and nothing in the chain ever asked whether that site
 * was theirs.
 *
 * A trait rather than middleware because the question is per-action: some registers legitimately
 * show several sites at once and need `scopeToPermittedSites()` on the query instead, and
 * middleware would have to guess which. Twelve controllers call one of the two, and both go
 * through the same service, so there is exactly one place that decides.
 */
trait ScopesToPermittedSites
{
    /**
     * The site this request may be answered for.
     *
     * Refuses with a 403 where the user named a site that is not theirs. Where a restricted user
     * named nothing and has exactly one site, theirs is returned — somebody assigned to one shaft
     * opening the permit register means "my shaft", and making them name it every time is
     * friction that teaches them to ask for `sheq.all_sites`.
     */
    protected function permittedSite(Request $request, ?int $companyId): ?int
    {
        $requested = $request->integer('site_id') ?: null;

        if ($companyId === null) {
            // No tenant resolved yet; the caller's own no-company path handles it, and asserting
            // an entitlement against a company that does not exist would be a worse error
            // message than the one they are about to get.
            return $requested;
        }

        return app(SiteEntitlement::class)
            ->resolveRequestedSite($companyId, $request->user(), $requested);
    }

    /**
     * Narrow a query to the sites this user may see.
     *
     * For the registers that show several sites at once. Rows with no site are kept: a statutory
     * obligation or a group policy belongs to everybody, and scoping those out hides records a
     * restricted user is entitled to.
     */
    protected function scopeToPermittedSites(mixed $query, Request $request, ?int $companyId,
        string $column = 'site_id', bool $allowNullSite = true): mixed
    {
        if ($companyId === null) {
            return $query;
        }

        return app(SiteEntitlement::class)
            ->scope($query, $companyId, $request->user(), $column, $allowNullSite);
    }

    /** The sites this user may see, or null for all of them — for sending to a screen. */
    protected function permittedSiteIds(Request $request, ?int $companyId): ?array
    {
        if ($companyId === null) {
            return null;
        }

        return app(SiteEntitlement::class)->sitesFor($companyId, $request->user());
    }
}
