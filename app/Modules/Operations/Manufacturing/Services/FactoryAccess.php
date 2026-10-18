<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\Auth;

/**
 * Which factories, and which departments within them, a person may work in (MFG-001.09).
 *
 * The rule, stated once because three things depend on it:
 *
 *   - Somebody with at least one assignment is confined to what they are assigned to.
 *   - Somebody with none is not confined. A tenant that has never assigned anybody is not locked
 *     out of its own plant, and "no assignments" is the state every existing tenant is in.
 *   - mfg.factory.all lifts the confinement, for the person who is meant to see every plant.
 *
 * The answer is memoised per user for the request. It is read by a global scope on a dozen models,
 * so unmemoised it is a query per query.
 */
class FactoryAccess
{
    /** @var array<int, array{factories: list<int>, departments: array<int, list<int>|null>}|null> */
    private array $memo = [];

    /**
     * The restriction for a user, or null when they are not restricted.
     *
     * `departments` maps a factory id to the departments the person is confined to inside it, or
     * to null when they hold the whole factory.
     *
     * @return array{factories: list<int>, departments: array<int, list<int>|null>}|null
     */
    public function restrictionFor(?User $user = null): ?array
    {
        $user ??= Auth::user();

        // A console command, a queued job or a seeder has no person to confine.
        if (! $user) {
            return null;
        }

        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->resolve($user);
    }

    /** Forget what was worked out, after an assignment changes. */
    public function flush(): void
    {
        $this->memo = [];
    }

    public function canSeeFactory(User $user, int $factoryId): bool
    {
        $restriction = $this->restrictionFor($user);

        return $restriction === null || in_array($factoryId, $restriction['factories'], true);
    }

    private function resolve(User $user): ?array
    {
        if (($user->is_platform_admin ?? false) || ($user->is_master_admin ?? false)) {
            return null;
        }

        if ($user->can(RoleCatalog::ALL_FACTORIES)) {
            return null;
        }

        $rows = FactoryAssignment::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->get(['factory_id', 'department_id']);

        if ($rows->isEmpty()) {
            return null;
        }

        $factories = [];
        $departments = [];

        foreach ($rows->groupBy('factory_id') as $factoryId => $group) {
            $factories[] = (int) $factoryId;

            // Any whole-factory assignment wins over department-level ones at the same plant:
            // being given the factory and one of its departments is being given the factory.
            $departments[(int) $factoryId] = $group->contains(fn ($r) => $r->department_id === null)
                ? null
                : $group->pluck('department_id')->map(fn ($d) => (int) $d)->values()->all();
        }

        return ['factories' => $factories, 'departments' => $departments];
    }
}
