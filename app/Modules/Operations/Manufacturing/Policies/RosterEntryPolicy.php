<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Rosters, qualifications and hours worked are personal data: read and kept by the labour grants. */
class RosterEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_LABOUR);
    }

    public function qualifications(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_LABOUR);
    }

    public function labour(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_LABOUR);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_LABOUR);
    }
}
