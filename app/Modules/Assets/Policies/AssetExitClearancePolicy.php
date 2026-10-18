<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetExitClearance;

class AssetExitClearancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view') || $user->hasPermissionTo('assets.exit-clearances');
    }

    public function view(User $user, AssetExitClearance $clearance): bool
    {
        return $user->hasPermissionTo('assets.view') || $user->hasPermissionTo('assets.exit-clearances');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('assets.exit-clearances');
    }

    public function resolveItem(User $user, AssetExitClearance $clearance): bool
    {
        return $user->hasPermissionTo('assets.exit-clearances')
            && ! in_array($clearance->status, ['completed', 'cancelled'], true);
    }

    public function complete(User $user, AssetExitClearance $clearance): bool
    {
        if (! $user->hasPermissionTo('assets.exit-clearances')) {
            return false;
        }

        return $clearance->items()
            ->whereIn('status', ['pending'])
            ->doesntExist();
    }

    public function delete(User $user, AssetExitClearance $clearance): bool
    {
        return $user->hasPermissionTo('assets.exit-clearances')
            && $clearance->status !== 'completed';
    }
}
