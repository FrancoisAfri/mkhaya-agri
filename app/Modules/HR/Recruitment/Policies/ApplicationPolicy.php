<?php

namespace App\Modules\HR\Recruitment\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Application;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.applications.view');
    }

    public function view(User $user, Application $application): bool
    {
        if (!$user->hasPermissionTo('recruitment.applications.view')) {
            return false;
        }

        // Recruiters may only see applications on jobs assigned to them
        if ($user->hasRole('recruiter')) {
            return $application->recruiter_id === $user->id;
        }

        return true;
    }

    public function move(User $user, Application $application): bool
    {
        if (!$user->hasPermissionTo('recruitment.applications.move')) {
            return false;
        }

        // A recruiter may only move applications on jobs they own
        if ($user->hasRole('recruiter')) {
            return $application->recruiter_id === $user->id;
        }

        return true;
    }

    public function reject(User $user, Application $application): bool
    {
        return $this->move($user, $application);
    }

    public function withdraw(User $user, Application $application): bool
    {
        // HR can withdraw on behalf of a candidate; recruiters can on their jobs
        return $user->hasPermissionTo('recruitment.applications.withdraw');
    }
}
