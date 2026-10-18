<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Operators raise escalations; supervisors answer them. */
class FloorEscalationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::SUPERVISE_FLOOR) || $user->can(RoleCatalog::EXECUTE) || $user->can(RoleCatalog::VIEW_ORDERS);
    }

    public function respond(User $user): bool
    {
        return $user->can(RoleCatalog::SUPERVISE_FLOOR);
    }
}
