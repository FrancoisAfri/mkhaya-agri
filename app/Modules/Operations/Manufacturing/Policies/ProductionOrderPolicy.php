<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Who may move a production order through its lifecycle (MFG-006).
 *
 * Each step is checked against the status it starts from as well as the permission, so a button is
 * only offered where pressing it can succeed -- the lifecycle service refuses the rest anyway, but a
 * button that always ends in an error teaches people to ignore errors.
 */
class ProductionOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ORDERS);
    }

    public function view(User $user, PO $order): bool
    {
        return $user->can(RoleCatalog::VIEW_ORDERS);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_ORDERS);
    }

    public function update(User $user, PO $order): bool
    {
        return $order->status === PO::DRAFT && $user->can(RoleCatalog::MANAGE_ORDERS);
    }

    public function plan(User $user, PO $order): bool
    {
        return $order->status === PO::DRAFT && $user->can(RoleCatalog::MANAGE_ORDERS);
    }

    public function unplan(User $user, PO $order): bool
    {
        return $order->status === PO::PLANNED && $user->can(RoleCatalog::MANAGE_ORDERS);
    }

    public function reopen(User $user, PO $order): bool
    {
        return $order->status === PO::REJECTED && $user->can(RoleCatalog::MANAGE_ORDERS);
    }

    public function approve(User $user, PO $order): bool
    {
        return $order->status === PO::PLANNED && $user->can(RoleCatalog::APPROVE_ORDERS);
    }

    public function reject(User $user, PO $order): bool
    {
        return $order->status === PO::PLANNED && $user->can(RoleCatalog::APPROVE_ORDERS);
    }

    public function release(User $user, PO $order): bool
    {
        return $order->status === PO::APPROVED && $user->can(RoleCatalog::RELEASE_ORDERS);
    }

    public function hold(User $user, PO $order): bool
    {
        return in_array($order->status, [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW], true)
            && $user->can(RoleCatalog::RELEASE_ORDERS);
    }

    public function resume(User $user, PO $order): bool
    {
        return $order->status === PO::ON_HOLD && $user->can(RoleCatalog::RELEASE_ORDERS);
    }

    /** Before anything is consumed. Whoever raised it or whoever approves may call it off. */
    public function cancel(User $user, PO $order): bool
    {
        return in_array($order->status, [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::ON_HOLD, PO::REJECTED], true)
            && ($user->can(RoleCatalog::MANAGE_ORDERS) || $user->can(RoleCatalog::APPROVE_ORDERS));
    }

    /** Production manager steps once the order is on the floor. */
    public function sendToQuality(User $user, PO $order): bool
    {
        return $order->status === PO::IN_PRODUCTION && $user->can(RoleCatalog::RELEASE_ORDERS);
    }

    public function returnToProduction(User $user, PO $order): bool
    {
        return $order->status === PO::QUALITY_REVIEW && $user->can(RoleCatalog::RELEASE_ORDERS);
    }

    public function complete(User $user, PO $order): bool
    {
        return $order->status === PO::QUALITY_REVIEW && $user->can(RoleCatalog::RELEASE_ORDERS);
    }

    public function close(User $user, PO $order): bool
    {
        return $order->status === PO::COMPLETED && $user->can(RoleCatalog::CLOSE_ORDERS);
    }

    /** Recording work on the floor: operations, gates, output and losses. */
    public function execute(User $user, PO $order): bool
    {
        return $order->isActive() && $user->can(RoleCatalog::EXECUTE);
    }

    public function issueMaterial(User $user, PO $order): bool
    {
        return $order->isActive() && $user->can(RoleCatalog::ISSUE_MATERIAL);
    }

    /** The shop-floor terminal: anybody who records work, issues material or runs the floor. */
    public function useFloor(User $user): bool
    {
        return $user->can(RoleCatalog::EXECUTE) || $user->can(RoleCatalog::ISSUE_MATERIAL) || $user->can(RoleCatalog::SUPERVISE_FLOOR);
    }

    /** Giving jobs to operators. */
    public function supervise(User $user, PO $order): bool
    {
        return $order->isActive() && $user->can(RoleCatalog::SUPERVISE_FLOOR);
    }
}
