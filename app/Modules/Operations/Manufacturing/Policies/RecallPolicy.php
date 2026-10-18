<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Tracing is a read across suppliers, orders and customers; a recall holds stock company-wide. */
class RecallPolicy
{
    public function trace(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_TRACE);
    }

    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_TRACE) || $user->can(RoleCatalog::MANAGE_RECALL);
    }

    public function view(User $user, Recall $recall): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_RECALL);
    }

    public function manage(User $user, Recall $recall): bool
    {
        return $recall->isOpen() && $user->can(RoleCatalog::MANAGE_RECALL);
    }
}
