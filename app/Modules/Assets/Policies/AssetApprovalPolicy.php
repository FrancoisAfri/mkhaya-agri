<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetApproval;

class AssetApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetApproval $approval): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function approve(User $user, AssetApproval $approval): bool
    {
        if (! $user->hasPermissionTo('assets.approve')) {
            return false;
        }

        if ((int) $approval->requested_by === (int) $user->id) {
            return false;
        }

        $status = $approval->status instanceof \BackedEnum ? $approval->status->value : $approval->status;
        return in_array($status, ['pending', 'in_review'], true);
    }

    public function reject(User $user, AssetApproval $approval): bool
    {
        return $this->approve($user, $approval);
    }

    public function cancel(User $user, AssetApproval $approval): bool
    {
        $status = $approval->status instanceof \BackedEnum ? $approval->status->value : $approval->status;
        if (! in_array($status, ['pending', 'in_review'], true)) {
            return false;
        }

        return (int) $approval->requested_by === (int) $user->id
            || $user->hasPermissionTo('assets.approve');
    }

    public function comment(User $user, AssetApproval $approval): bool
    {
        return $user->hasPermissionTo('assets.view');
    }
}
