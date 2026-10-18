<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Alert routing is configuration (MFG-020); its log shows who was told what, so it is too. */
class NotificationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }

    public function log(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }
}
