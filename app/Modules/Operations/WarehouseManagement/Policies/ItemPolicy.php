<?php

namespace App\Modules\Operations\WarehouseManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;

/**
 * Who may read and change the product master from the warehouse side. The subject is Finance's
 * item because a WHMI item IS that row; nothing in Finance authorises through a policy on it.
 *
 * Changing a status is its own permission: it stops stock moving across every warehouse.
 */
class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW);
    }

    public function view(User $user, FinStockItem $item): bool
    {
        return $user->can(RoleCatalog::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_ITEMS);
    }

    public function update(User $user, FinStockItem $item): bool
    {
        return $user->can(RoleCatalog::MANAGE_ITEMS);
    }

    public function changeStatus(User $user, FinStockItem $item): bool
    {
        return $user->can(RoleCatalog::CHANGE_ITEM_STATUS);
    }

    public function approveDuplicateBarcode(User $user, ?FinStockItem $item = null): bool
    {
        return $user->can(RoleCatalog::APPROVE_DUPLICATE_BARCODES);
    }
}
