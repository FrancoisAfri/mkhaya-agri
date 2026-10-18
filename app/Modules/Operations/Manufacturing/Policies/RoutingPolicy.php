<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Who may read, draft, approve and retire a routing. `update` is false for anything but a draft
 * whatever the user holds -- the database refuses the write anyway, and an Edit button that ends in
 * a database exception is worse than no button.
 */
class RoutingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ROUTINGS);
    }

    public function view(User $user, Routing $routing): bool
    {
        return $user->can(RoleCatalog::VIEW_ROUTINGS);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_ROUTINGS);
    }

    public function update(User $user, Routing $routing): bool
    {
        return $routing->isEditable() && $user->can(RoleCatalog::MANAGE_ROUTINGS);
    }

    public function delete(User $user, Routing $routing): bool
    {
        return $routing->status === Routing::DRAFT && $user->can(RoleCatalog::MANAGE_ROUTINGS);
    }

    public function revise(User $user, Routing $routing): bool
    {
        return in_array($routing->status, [Routing::APPROVED, Routing::SUPERSEDED], true)
            && $user->can(RoleCatalog::MANAGE_ROUTINGS);
    }

    public function approve(User $user, Routing $routing): bool
    {
        return $routing->status === Routing::DRAFT && $user->can(RoleCatalog::APPROVE_ROUTINGS);
    }

    public function obsolete(User $user, Routing $routing): bool
    {
        return $routing->status !== Routing::OBSOLETE && $user->can(RoleCatalog::APPROVE_ROUTINGS);
    }
}
