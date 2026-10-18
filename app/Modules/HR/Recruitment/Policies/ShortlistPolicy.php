<?php

namespace App\Modules\HR\Recruitment\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;

class ShortlistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.shortlists.view');
    }

    public function view(User $user, Shortlist $shortlist): bool
    {
        return $user->hasPermissionTo('recruitment.shortlists.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.shortlists.create');
    }

    public function approve(User $user, Shortlist $shortlist): bool
    {
        if (!$user->hasPermissionTo('recruitment.shortlists.approve')) {
            return false;
        }

        // Self-approval blocked here and in ApproveShortlistAction — double enforcement
        return $user->id !== $shortlist->generated_by;
    }

    public function reject(User $user, Shortlist $shortlist): bool
    {
        return $user->hasPermissionTo('recruitment.shortlists.approve');
    }

    public function override(User $user, Shortlist $shortlist): bool
    {
        return $user->hasPermissionTo('recruitment.shortlists.override');
    }

    public function approveOverride(User $user, ShortlistOverride $override): bool
    {
        if (!$user->hasPermissionTo('recruitment.shortlists.override')) {
            return false;
        }

        // Four-eyes: approver cannot be the same person who requested the override
        return $user->id !== $override->overridden_by;
    }
}
