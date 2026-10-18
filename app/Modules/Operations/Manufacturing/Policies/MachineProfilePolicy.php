<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Maintenance is read by maintenance and production, managed by maintenance, and a held machine is
 * returned to service only by those who may sign that it is safe.
 */
class MachineProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_MAINTENANCE);
    }

    public function preventive(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_MAINTENANCE);
    }

    public function oee(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_MAINTENANCE) || $user->can(RoleCatalog::VIEW_ORDERS);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_MAINTENANCE);
    }

    public function release(User $user): bool
    {
        return $user->can(RoleCatalog::RELEASE_MACHINE);
    }

    /** A breakdown is reported by whoever is at the machine. */
    public function reportBreakdown(User $user): bool
    {
        return $user->can(RoleCatalog::EXECUTE) || $user->can(RoleCatalog::MANAGE_MAINTENANCE);
    }
}
