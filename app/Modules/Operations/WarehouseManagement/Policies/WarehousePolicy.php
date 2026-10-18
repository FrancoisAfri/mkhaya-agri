<?php

namespace App\Modules\Operations\WarehouseManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;

/**
 * Who may read and shape a warehouse.
 *
 * The subject is Finance's warehouse because a WHMI warehouse IS that row (WMS-002). Finance's own
 * stock screens do not authorise through a policy, so registering this one changes nothing there.
 */
class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW);
    }

    public function view(User $user, FinWarehouse $warehouse): bool
    {
        return $user->can(RoleCatalog::VIEW) && app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAccess::class)->mayUse($user, $warehouse->id);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_WAREHOUSES);
    }

    public function update(User $user, FinWarehouse $warehouse): bool
    {
        return $user->can(RoleCatalog::MANAGE_WAREHOUSES) && app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAccess::class)->mayUse($user, $warehouse->id);
    }

    public function delete(User $user, FinWarehouse $warehouse): bool
    {
        return $user->can(RoleCatalog::MANAGE_WAREHOUSES);
    }

    /** Add, edit and retire locations. Called without a warehouse for the card gate. */
    public function manageLocations(User $user, ?FinWarehouse $warehouse = null): bool
    {
        return $user->can(RoleCatalog::MANAGE_LOCATIONS);
    }

    public function blockLocations(User $user, ?FinWarehouse $warehouse = null): bool
    {
        return $user->can(RoleCatalog::BLOCK_LOCATIONS) || $user->can(RoleCatalog::MANAGE_LOCATIONS);
    }
}
