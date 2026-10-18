<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Who may read and change the engineering definition of a product.
 *
 * Reading is granted widely on purpose: a buyer needs the material grade, a planner needs the
 * BOM, a supervisor needs the handling instructions. Writing is not — an engineering change
 * silently alters what every future order consumes and costs.
 */
class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ITEMS);
    }

    public function view(User $user, Item $item): bool
    {
        return $user->can(RoleCatalog::VIEW_ITEMS);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_ITEMS);
    }

    public function update(User $user, Item $item): bool
    {
        return $user->can(RoleCatalog::MANAGE_ITEMS);
    }

    public function delete(User $user, Item $item): bool
    {
        return $user->can(RoleCatalog::MANAGE_ITEMS);
    }

    /** Signing off a substitution somebody recorded as requiring one. */
    public function approveSubstitution(User $user): bool
    {
        return $user->can(RoleCatalog::APPROVE_SUBSTITUTIONS);
    }
}
