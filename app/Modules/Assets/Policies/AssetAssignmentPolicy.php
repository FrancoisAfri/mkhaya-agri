<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetAssignment;

class AssetAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetAssignment $assignment): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('assets.assign');
    }

    public function return(User $user, AssetAssignment $assignment): bool
    {
        if (! $user->hasPermissionTo('assets.assignment.return')) {
            return false;
        }

        return $assignment->is_current && ! $assignment->returned_at;
    }

    public function recall(User $user, AssetAssignment $assignment): bool
    {
        return $user->hasPermissionTo('assets.assign') && $assignment->is_current;
    }

    public function extend(User $user, AssetAssignment $assignment): bool
    {
        return $user->hasPermissionTo('assets.assign')
            && $assignment->is_current
            && $assignment->return_due_date !== null;
    }
}
