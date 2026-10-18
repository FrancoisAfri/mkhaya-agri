<?php

namespace App\Modules\CRM\Services;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Row-level visibility for CRM records, by role:
 *  - Sales consultant      → only records they own.
 *  - Sales manager/manager → their own + everyone in their reporting tree.
 *  - Admin / super admin / sales executive / executive → everything.
 *  - Anyone else (no sales role) → everything (backwards compatible).
 *
 * "Visible owner ids" of null means unrestricted (see all).
 */
class CrmVisibilityService
{
    /** Roles that may see every record. */
    private const ALL_ACCESS = ['administrator', 'super admin', 'superadmin', 'sales executive', 'executive'];

    /** Roles that see their reporting tree. */
    private const TEAM_ACCESS = ['sales manager', 'manager', 'senior manager'];

    /** Roles restricted to their own records. */
    private const OWN_ONLY = ['sales consultant'];

    /** What somebody is looking at: their own work, or everything they are permitted to see. */
    public const SCOPE_MINE = 'mine';

    public const SCOPE_TEAM = 'team';

    public function __construct(private CrmTeamService $teams)
    {
    }

    /**
     * The owner ids the given user (default: current) may see, or null for all.
     *
     * @return array<int,int>|null
     */
    public function visibleOwnerIds(?User $user = null): ?array
    {
        $user ??= Auth::user();
        if (! $user) {
            return [0]; // unauthenticated — see nothing
        }

        if ($user->hasAnyRole(self::ALL_ACCESS)) {
            return null;
        }
        if ($user->hasAnyRole(self::TEAM_ACCESS)) {
            return $this->teams->subtreeOf((int) $user->id);
        }
        if ($user->hasAnyRole(self::OWN_ONLY)) {
            return [(int) $user->id];
        }

        // No sales role → unrestricted (e.g. other admin/staff).
        return null;
    }

    /** True when the current user can see everything. */
    public function seesAll(?User $user = null): bool
    {
        return $this->visibleOwnerIds($user) === null;
    }

    /**
     * True when the user can see records owned by people other than themselves —
     * i.e. show the "owner / sales rep" column. False for own-only consultants.
     */
    public function seesOthers(?User $user = null): bool
    {
        $user ??= Auth::user();
        $ids = $this->visibleOwnerIds($user);
        if ($ids === null) {
            return true;
        }
        $self = (int) ($user?->id ?? 0);
        return count($ids) > 1 || (count($ids) === 1 && $ids[0] !== $self);
    }

    /**
     * Constrain a query to what the current user should be looking at.
     *
     * Two different questions live here and they used to be one. `visibleOwnerIds()` answers what
     * somebody is *permitted* to see; this answers what they are *looking at now*. A manager is
     * permitted to see their whole team, but opening the deals board and being shown forty
     * colleagues' deals buries their own — so the default is their own work, and seeing the team's
     * is a deliberate act.
     *
     * `$scope` comes from the request: 'mine' narrows to the caller's own, 'team' is everything
     * they may see. A scope of 'team' from somebody with no team resolves to their own records, so
     * the parameter can never widen what the permission model allows.
     *
     * Omitting it keeps the older behaviour — everything the caller is permitted to see. Only the
     * deals board asks to be narrowed today, and changing what the other five lists show is a
     * decision for whoever owns those screens, not a side effect of this one.
     */
    public function scopeOwner(Builder $query, string $column = 'owner_id', ?User $user = null, ?string $scope = null): Builder
    {
        if ($scope === null) {
            $ids = $this->visibleOwnerIds($user);

            if ($ids !== null) {
                $query->whereIn($column, $ids);
            }

            return $query;
        }

        $ids = $this->ownerIdsForScope($scope, $user);

        if ($ids === null) {
            return $query;
        }

        /*
         * Unassigned records come along too.
         *
         * A deal nobody owns is work waiting to be picked up. Reading "mine" strictly would hide it
         * from every single person — the rep does not own it, and the manager looking at their team
         * does not either — so it would sit there until somebody went looking for it, which nobody
         * does. It is also what an installation looks like before anyone has assigned anything, and
         * an empty board on day one reads as the feature being broken.
         */
        $query->where(fn ($q) => $q->whereIn($column, $ids)->orWhereNull($column));

        return $query;
    }

    /**
     * The owner ids to list for a given scope, or null for everything.
     *
     * Unassigned records are handled by the caller rather than here: this answers "whose", and
     * "nobody's" is not an id.
     *
     * @return array<int,int>|null
     */
    public function ownerIdsForScope(string $scope, ?User $user = null): ?array
    {
        $permitted = $this->visibleOwnerIds($user);

        if ($scope !== self::SCOPE_MINE) {
            return $permitted;
        }

        $user ??= Auth::user();
        $self = (int) ($user?->id ?? 0);

        /*
         * Narrowed to themselves — but only within what they are allowed to see, so a scope of
         * 'mine' can never become a way to read a record the permission model would refuse.
         */
        return $permitted === null || in_array($self, $permitted, true) ? [$self] : $permitted;
    }

    /** The scope to use for a request, defaulting to the user's own records. */
    public function scopeFromRequest(?string $requested, ?User $user = null): string
    {
        return $requested === self::SCOPE_TEAM && $this->seesOthers($user)
            ? self::SCOPE_TEAM
            : self::SCOPE_MINE;
    }

    /** True when the user may act on a record owned by the given user. */
    public function canSee(?int $ownerId, ?User $user = null): bool
    {
        $ids = $this->visibleOwnerIds($user);

        return $ids === null || in_array((int) $ownerId, $ids, true);
    }

    /**
     * Refuse the request unless the record is inside the user's reporting tree.
     *
     * Scoping the list is not the same as protecting the record: without this, a record simply
     * absent from someone's list is still reachable by id, so anyone could change or delete a
     * colleague's — or another team's — lead by guessing a number.
     */
    public function authorize(Model $record, string $column = 'owner_id', ?User $user = null): void
    {
        abort_unless(
            $this->canSee($record->getAttribute($column) === null ? null : (int) $record->getAttribute($column), $user),
            403,
            'This record belongs to someone outside your team.',
        );
    }

    /**
     * The people the current user may hand a record to, for the reassign picker.
     *
     * Deliberately the same set as they can see: a manager reassigns within their own tree, an
     * executive anywhere. Handing a record to someone you cannot see would put it beyond your
     * own reach the moment you saved it.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function assignableOwners(?User $user = null): array
    {
        $ids = $this->visibleOwnerIds($user);

        $query = \Illuminate\Support\Facades\DB::table('users')
            ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.status', 1);

        /*
         * Scoped to the tenant, which it was not.
         *
         * visibleOwnerIds() returns null for an administrator and for anybody with no sales role
         * at all — the common case — and a null there means "no restriction by owner". It was
         * being read as "no restriction at all": this goes through the query builder against
         * `users`, a table that carries no row-level security, so the dropdown listed every
         * account on the installation. On a multi-tenant install that is one customer's staff
         * offered as another customer's account owner, and their names on the screen either way.
         *
         * Self-hosted keeps the people who belong to no company, for the same reason the staff
         * picker does: with one tenant there is nothing to confuse them with, and the first
         * administrator is usually the one with no company stamped.
         */
        $companyId = $this->tenantCompanyId();
        if ($companyId !== null) {
            $query->where(fn ($q) => \App\Modules\Tenancy\Services\Edition::isSelfHosted()
                ? $q->where('users.company_id', $companyId)->orWhereNull('users.company_id')
                : $q->where('users.company_id', $companyId));
        }

        if ($ids !== null) {
            $query->whereIn('users.id', $ids);
        }

        return $query
            ->selectRaw("users.id, COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name, ' ', COALESCE(hr_people.surname, ''))), ''), users.email) AS name")
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->all();
    }

    /**
     * The company whose people may be offered, or null when there is genuinely no answer.
     *
     * Null means a platform administrator who has not entered a tenant. They already see every
     * account in the installation, so withholding the names of the people those accounts belong to
     * protects nothing — the same call ResolvesTenant makes for the modules that use it.
     */
    private function tenantCompanyId(): ?int
    {
        // id() is an instance method on the bound singleton — calling it statically fatals.
        $companyId = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id()
            ?? Auth::user()?->company_id;

        if (! $companyId && \App\Modules\Tenancy\Services\Edition::isSelfHosted()) {
            $companies = \Illuminate\Support\Facades\DB::table('companies')->limit(2)->pluck('id');
            $companyId = $companies->count() === 1 ? $companies->first() : null;
        }

        return $companyId ? (int) $companyId : null;
    }
}
