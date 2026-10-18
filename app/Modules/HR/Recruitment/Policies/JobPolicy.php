<?php

namespace App\Modules\HR\Recruitment\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Job;

class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.jobs.view');
    }

    public function view(User $user, Job $job): bool
    {
        return $user->hasPermissionTo('recruitment.jobs.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.jobs.create');
    }

    public function update(User $user, Job $job): bool
    {
        return $user->hasPermissionTo('recruitment.jobs.edit');
    }

    public function delete(User $user, Job $job): bool
    {
        if (!$user->hasPermissionTo('recruitment.jobs.delete')) {
            return false;
        }

        // Cannot delete a job that has active applications
        return $job->activeApplications()->doesntExist();
    }
}
