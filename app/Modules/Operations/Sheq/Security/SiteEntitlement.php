<?php

namespace App\Modules\Operations\Sheq\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Which sites a user may open (SEC-2, TC-SEC-02).
 *
 * The derivation already existed and was already used in two places — the document library's
 * audience filter and the sync pull both work out a user's sites through
 * `company_unit_user_pivots` joined to `sheq_sites.company_unit_id`. What did not exist is the
 * control. A user with module access could read any site's permits, attendance or incidents by
 * changing a filter, because the site filter was a convenience and never an entitlement.
 *
 * **`null` means unrestricted, and an empty array means nothing.** Those are two different
 * answers and conflating them is the whole danger here. The pre-existing derivation returns `[]`
 * for a user with no unit assignment, which is right for "which site-specific documents apply to
 * you" and catastrophic for "which sites may you open" — on this database not one user has a unit
 * assignment, so reading `[]` as an entitlement would lock every person out of every screen while
 * reporting success. That is the failure mode this module has hit before in another form, and the
 * reason the return type is nullable rather than a list.
 *
 * Off until a host switches it on, per tenant. The reasoning is in the migration: a unit
 * assignment is an HR act maintained for payroll reasons, so enforcing on it globally would
 * re-segregate every existing installation without being asked.
 */
class SiteEntitlement
{
    /**
     * Resolved once per request per user.
     *
     * A register renders a list of rows and asks about each one; without this the entitlement
     * would be two queries per row.
     *
     * @var array<string, array{restricted:bool, sites:list<int>}>
     */
    private array $cache = [];

    /**
     * The sites this user may open, or null where they may open any.
     *
     * @return list<int>|null
     */
    public function sitesFor(int $companyId, ?Authenticatable $user): ?array
    {
        $resolved = $this->resolve($companyId, $user);

        return $resolved['restricted'] ? $resolved['sites'] : null;
    }

    /** Whether this user is restricted at all. */
    public function isRestricted(int $companyId, ?Authenticatable $user): bool
    {
        return $this->resolve($companyId, $user)['restricted'];
    }

    /**
     * May this user open this site?
     *
     * A null site is the group view — every site at once — and an unrestricted user may have it.
     * A restricted user may NOT: "all sites" is precisely the thing they are not entitled to, and
     * letting the group view through because it names no site in particular would make the whole
     * control bypassable by removing a query parameter.
     */
    public function mayAccess(int $companyId, ?Authenticatable $user, ?int $siteId): bool
    {
        $sites = $this->sitesFor($companyId, $user);

        if ($sites === null) {
            return true;
        }

        if ($siteId === null) {
            return false;
        }

        return in_array($siteId, $sites, true);
    }

    /**
     * Refuse, with a message that says what to do.
     *
     * 403 and not a silent empty list. A register that quietly returns nothing teaches somebody
     * that the site is empty, which is a worse answer than being told they may not look at it —
     * they will act on "no open permits here" and they will be wrong.
     */
    public function assertMayAccess(int $companyId, ?Authenticatable $user, ?int $siteId): void
    {
        if ($this->mayAccess($companyId, $user, $siteId)) {
            return;
        }

        $sites = $this->sitesFor($companyId, $user) ?? [];

        throw new AccessDeniedHttpException(sprintf(
            'SITE_NOT_PERMITTED: you are assigned to %s. %s',
            $sites === [] ? 'no site on this system'
                : count($sites).' site(s), and this is not one of them',
            $sites === []
                ? 'Ask your SHEQ administrator to assign you to a site, or to grant '
                    .'sheq.all_sites if you need the whole estate.'
                : 'Choose one of your own sites, or ask for sheq.all_sites.',
        ));
    }

    /**
     * The site a request may actually be answered for.
     *
     * Where a restricted user asks for no site in particular, they get their own — which is the
     * useful behaviour rather than a refusal: somebody assigned to one shaft opening the permit
     * register means "my shaft", and making them name it every time is friction that teaches
     * them to ask for `sheq.all_sites`.
     *
     * Where they have several and name none, null comes back and the caller scopes the query
     * instead. Where they name one they are not entitled to, this refuses.
     */
    public function resolveRequestedSite(int $companyId, ?Authenticatable $user,
        ?int $requested): ?int
    {
        $sites = $this->sitesFor($companyId, $user);

        if ($sites === null) {
            return $requested;
        }

        if ($requested !== null) {
            $this->assertMayAccess($companyId, $user, $requested);

            return $requested;
        }

        return count($sites) === 1 ? $sites[0] : null;
    }

    /**
     * Scope a query to what this user may see.
     *
     * Used where a register legitimately shows several sites at once. A restricted user with no
     * sites gets `whereRaw('false')` rather than an unscoped query — the one case where failing
     * closed is right, because by then we know they ARE restricted and simply have nothing.
     *
     * @template T of QueryBuilder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    public function scope(mixed $query, int $companyId, ?Authenticatable $user,
        string $column = 'site_id', bool $allowNullSite = true): mixed
    {
        $sites = $this->sitesFor($companyId, $user);

        if ($sites === null) {
            return $query;
        }

        if ($sites === []) {
            return $query->whereRaw('false');
        }

        return $query->where(function ($where) use ($sites, $column, $allowNullSite) {
            $where->whereIn($column, $sites);

            /*
             * A row with no site is company-wide and belongs to everybody — a statutory
             * obligation, a group policy. Scoping those out would hide records a restricted user
             * is entitled to and is the commonest way a site filter becomes a bug report.
             */
            if ($allowNullSite) {
                $where->orWhereNull($column);
            }
        });
    }

    /** @return array{restricted:bool, sites:list<int>} */
    private function resolve(int $companyId, ?Authenticatable $user): array
    {
        $userId = $user?->getAuthIdentifier();
        $key = $companyId.':'.($userId ?? 'guest');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->compute($companyId, $user, $userId);
    }

    /** @return array{restricted:bool, sites:list<int>} */
    private function compute(int $companyId, ?Authenticatable $user, mixed $userId): array
    {
        $unrestricted = ['restricted' => false, 'sites' => []];

        // Off unless a host switched it on. See the migration for why this is not the default.
        $enabled = (bool) DB::table('sheq_settings')
            ->where('company_id', $companyId)->value('restrict_users_to_sites');

        if (! $enabled) {
            return $unrestricted;
        }

        if ($userId === null) {
            /*
             * No user at all. That is the public pass endpoint and the API, both of which carry
             * their own credential and are scoped by it — a token names one worker. Restricting
             * them here would break the gate, and they are not the thing SEC-2 is about.
             */
            return $unrestricted;
        }

        if (method_exists($user, 'can')
            && ($user->can('sheq.all_sites') || $user->can('sheq.configure'))) {
            // A group SHEQ manager sits in head office and must still see every shaft. Without
            // this the control makes the role impossible and somebody turns it off.
            return $unrestricted;
        }

        /*
         * The pivot's column is named `company_id` and holds the UNIT id — a long-standing naming
         * trap in this schema. Reading it as a company would match every site in the tenant,
         * which would make a restricted user unrestricted and the failure would look exactly like
         * the feature working.
         */
        $unitIds = DB::table('company_unit_user_pivots')
            ->where('user_id', $userId)
            ->pluck('company_id');

        if ($unitIds->isEmpty()) {
            /*
             * No unit assignment, so nothing has said which sites are theirs.
             *
             * Unrestricted, deliberately, and this is the single most important line in the
             * class. Reading it as "no sites" would lock out every user on an installation where
             * nobody maintains the unit tree — which on this database is all of them — and a
             * register that silently empties is read as "the site is clear" rather than as "you
             * may not look".
             */
            return $unrestricted;
        }

        $sites = DB::table('sheq_sites')
            ->where('company_id', $companyId)
            ->whereIn('company_unit_id', $unitIds)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['restricted' => true, 'sites' => array_values($sites)];
    }
}
