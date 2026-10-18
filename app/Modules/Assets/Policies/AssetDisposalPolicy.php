<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\Assets;

class AssetDisposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function request(User $user, Assets $asset): bool
    {
        if (! $user->hasPermissionTo('assets.dispose')) {
            return false;
        }

        return ! in_array($asset->asset_status, ['disposed', 'sold'], true);
    }

    public function approve(User $user, AssetApproval $approval): bool
    {
        if (! $user->hasPermissionTo('assets.dispose') || ! $user->hasPermissionTo('assets.approve')) {
            return false;
        }

        if ((int) $approval->requested_by === (int) $user->id) {
            return false;
        }

        return in_array($approval->status, ['pending', 'in_review'], true);
    }

    public function complete(User $user, Assets $asset): bool
    {
        return $user->hasPermissionTo('assets.dispose')
            && $asset->asset_status !== 'disposed';
    }
}
