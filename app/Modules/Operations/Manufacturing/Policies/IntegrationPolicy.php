<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** External integrations (MFG-022): seeing their status is one grant, holding their credentials another. */
class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_INTEGRATIONS);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_INTEGRATIONS);
    }
}
