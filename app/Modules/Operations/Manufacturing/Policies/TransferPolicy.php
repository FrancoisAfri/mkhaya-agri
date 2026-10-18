<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Inter-factory transfers and the factory network (MFG-024). Which factory a person acts for is checked in the service. */
class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_TRANSFERS);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_TRANSFERS);
    }

    public function network(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_TRANSFERS);
    }

    public function configure(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }
}
