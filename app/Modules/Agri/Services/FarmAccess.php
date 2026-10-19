<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Support\Facades\Auth;

/**
 * Which farms a person may reach (spec §3.2, §15).
 *
 * The rule, stated once because every farm-owned query depends on it:
 *
 *   - Platform and master administrators, and anyone holding agri.farm.all, reach every farm in
 *     their organisation.
 *   - Everybody else reaches exactly the farms they hold an ACTIVE membership on — and with none,
 *     no farm at all. Access is granted, never assumed: a farm manager who has not been assigned
 *     must not see the farm next door (spec 3.4).
 *
 * This deliberately differs from Manufacturing's "no assignments means unrestricted": that rule
 * keeps old tenants working, but here the specification makes farm isolation the default.
 *
 * Suspending a membership takes effect on the very next request; the answer is memoised for one
 * request only, and flush() clears it the moment a membership changes.
 */
class FarmAccess
{
    /** @var array<int, list<int>|null> user id → farm ids, or null for every farm */
    private array $memo = [];

    /**
     * The farm ids a user is confined to, or null when they may reach every farm.
     *
     * @return list<int>|null
     */
    public function farmIdsFor(?User $user = null): ?array
    {
        $user ??= Auth::user();

        // A console command, queued job or seeder acts for the system, not for a person.
        if (! $user) {
            return null;
        }

        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->resolve($user);
    }

    public function canReach(?User $user, int $farmId): bool
    {
        $ids = $this->farmIdsFor($user);

        return $ids === null || in_array($farmId, $ids, true);
    }

    /** True when the person reaches every farm in the organisation. */
    public function isUnrestricted(?User $user = null): bool
    {
        return $this->farmIdsFor($user) === null;
    }

    /** The role a person holds on a farm, or null without a membership (unrestricted users included). */
    public function roleOn(User $user, int $farmId): ?string
    {
        return FarmMembership::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('farm_id', $farmId)
            ->where('status', FarmMembership::ACTIVE)
            ->value('role');
    }

    /**
     * Whether a person may change records on a farm, not just read them. A "viewer" membership is
     * read-only whatever permissions the person's role carries; everyone unrestricted may write.
     */
    public function canWrite(User $user, int $farmId): bool
    {
        if (! $this->canReach($user, $farmId)) {
            return false;
        }

        return $this->isUnrestricted($user) || $this->roleOn($user, $farmId) !== 'viewer';
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** @return list<int>|null */
    private function resolve(User $user): ?array
    {
        if (($user->is_platform_admin ?? false) || ($user->is_master_admin ?? false)) {
            return null;
        }

        if ($user->can(RoleCatalog::ALL_FARMS)) {
            return null;
        }

        return FarmMembership::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', FarmMembership::ACTIVE)
            ->pluck('farm_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
