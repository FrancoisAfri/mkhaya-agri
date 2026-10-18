<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Costs and margins are read by few; a standard is calculated by one permission and approved by another. */
class StandardCostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_COSTS);
    }

    public function view(User $user, StandardCost $sc): bool
    {
        return $user->can(RoleCatalog::VIEW_COSTS);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_COSTS);
    }

    public function approve(User $user, StandardCost $sc): bool
    {
        return $sc->status === StandardCost::DRAFT && $user->can(RoleCatalog::APPROVE_COSTS);
    }

    public function variances(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_COSTS);
    }

    public function profitability(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_COSTS);
    }
}
