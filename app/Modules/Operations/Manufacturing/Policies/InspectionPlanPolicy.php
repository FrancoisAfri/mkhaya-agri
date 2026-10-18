<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Who may define what gets inspected, and to which limits. */
class InspectionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY);
    }

    public function view(User $user, InspectionPlan $plan): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_QUALITY_PLANS);
    }

    public function update(User $user, InspectionPlan $plan): bool
    {
        return $user->can(RoleCatalog::MANAGE_QUALITY_PLANS);
    }
}
