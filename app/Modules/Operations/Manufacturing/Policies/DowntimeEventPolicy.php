<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Downtime is recorded by the floor, read by production, and its reason codes set up by configuration. */
class DowntimeEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ORDERS) || $user->can(RoleCatalog::EXECUTE) || $user->can(RoleCatalog::SUPERVISE_FLOOR);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::EXECUTE) || $user->can(RoleCatalog::SUPERVISE_FLOOR);
    }

    public function manageReasons(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }
}
