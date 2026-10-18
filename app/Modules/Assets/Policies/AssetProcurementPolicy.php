<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetPurchaseRequest;

class AssetProcurementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.procurement') || $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetPurchaseRequest $request): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('assets.procurement');
    }

    public function update(User $user, AssetPurchaseRequest $request): bool
    {
        return $user->hasPermissionTo('assets.procurement')
            && in_array($request->status, ['draft', 'requested'], true);
    }

    public function approve(User $user, AssetPurchaseRequest $request): bool
    {
        if (! $user->hasPermissionTo('assets.procurement') || ! $user->hasPermissionTo('assets.approve')) {
            return false;
        }

        if ((int) $request->requested_by_user_id === (int) $user->id) {
            return false;
        }

        return $request->status === 'requested';
    }

    public function receive(User $user, AssetPurchaseRequest $request): bool
    {
        return $user->hasPermissionTo('assets.procurement')
            && in_array($request->status, ['approved', 'ordered'], true);
    }
}
