<?php

namespace App\Modules\HR\PreBoarding\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\PreBoarding\Models\HrPerson;

class HrPersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view_pre_boarding', 'manage_pre_boarding']);
    }

    public function view(User $user, HrPerson $person): bool
    {
        return $user->hasAnyPermission(['view_pre_boarding', 'manage_pre_boarding']);
    }

    public function manageTask(User $user, HrPerson $person): bool
    {
        return $user->hasPermissionTo('manage_pre_boarding');
    }
}
