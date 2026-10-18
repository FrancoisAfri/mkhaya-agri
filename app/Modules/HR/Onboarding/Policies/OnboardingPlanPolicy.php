<?php

namespace App\Modules\HR\Onboarding\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;

class OnboardingPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['view_onboarding', 'manage_onboarding']);
    }

    public function view(User $user, OnboardingPlan $plan): bool
    {
        return $user->hasAnyPermission(['view_onboarding', 'manage_onboarding']);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_onboarding');
    }

    public function manageTask(User $user, OnboardingPlan $plan): bool
    {
        return $user->hasPermissionTo('manage_onboarding');
    }
}
