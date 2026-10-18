<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Which warehouses a person may work in (WMS-200).
 *
 * A warehouse is open to everybody with the module's permissions unless its policy
 * `restrict_to_staff` is on; then only its assigned staff, its manager and holders of
 * `wms.warehouses.all` see and move its stock. Stock movements are refused in the ledger itself
 * (InventoryService); this class narrows what screens, reports and the API show.
 */
class WarehouseAccess
{
    /** @return list<int>|null the warehouses visible to this person; null when nothing is restricted for them */
    public function visibleIds(?User $user, ?int $companyId = null): ?array
    {
        if ($user === null || $user->can(RoleCatalog::ALL_WAREHOUSES)) {
            return null;
        }
        $cid = $companyId ?? app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $user->company_id;
        $all = DB::table('fin_warehouses')->when($cid, fn ($q, $c) => $q->where('company_id', $c))->get(['id', 'policies', 'manager_user_id']);
        $restricted = $all->filter(fn ($w) => (bool) (json_decode((string) $w->policies, true)['restrict_to_staff'] ?? false));
        if ($restricted->isEmpty()) {
            return null;
        }
        $mine = DB::table('wms_warehouse_staff')->where('user_id', $user->id)->where('is_active', true)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();

        return $all->filter(fn ($w) => ! $restricted->contains('id', $w->id) || in_array((int) $w->id, $mine, true) || (int) $w->manager_user_id === (int) $user->id)
            ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    public function mayUse(?User $user, int $warehouseId): bool
    {
        $ids = $this->visibleIds($user);

        return $ids === null || in_array($warehouseId, $ids, true);
    }
}
