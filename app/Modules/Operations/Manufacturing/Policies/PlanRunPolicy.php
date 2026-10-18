<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Planners read and run the plan; approving recommendations into orders and requests is separate. */
class PlanRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_PLANNING);
    }

    public function mrp(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_PLANNING);
    }

    public function suppliers(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_PLANNING);
    }

    public function parameters(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_PLANNING);
    }

    public function schedule(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_PLANNING);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_PLANNING);
    }

    public function approve(User $user): bool
    {
        return $user->can(RoleCatalog::APPROVE_PLAN);
    }
}
