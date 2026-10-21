<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Agricultural production: reading cycles, recording what happened in them, and setting up the
 * types, units and devices they run on. Which farm a person may act on is still their farm access,
 * checked by the controller on top of this.
 */
class AgriculturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_AGRICULTURE) || $user->can(RoleCatalog::MANAGE_AGRICULTURE);
    }

    public function view(User $user, Cycle $cycle): bool
    {
        return $this->viewAny($user);
    }

    public function record(User $user): bool
    {
        return $user->can(RoleCatalog::RECORD_AGRICULTURE) || $user->can(RoleCatalog::MANAGE_AGRICULTURE);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_AGRICULTURE);
    }

    /** Grading, packing and processing runs, and releasing what a failed check held. */
    public function process(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_AGRICULTURE) || $user->can(RoleCatalog::INSPECT) || $user->can(RoleCatalog::DISPOSE);
    }
}
