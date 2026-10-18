<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Models\ClientAccess;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * WMS-001: a third-party client's stock is visible only to people explicitly granted that client,
 * or holding wms.clients.all. The company's own stock (no owner) is visible as before.
 *
 * Applied to every warehouse list that shows items or stock, so a grant is a boundary on the
 * stock screen, the history and the availability figures alike.
 */
class ClientVisibility
{
    /** @return list<int>|null null = every client */
    public function clientsFor(?User $user): ?array
    {
        if ($user === null || $user->can(RoleCatalog::ALL_CLIENTS)) {
            return null;
        }

        return ClientAccess::query()->where('user_id', $user->id)->pluck('party_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Narrow a query whose rows carry an item: $column is that query's item id column. */
    public function scope(Builder|\Illuminate\Database\Eloquent\Builder $query, string $itemColumn = 'id', ?User $user = null): void
    {
        $clients = $this->clientsFor($user ?? Auth::user());
        if ($clients === null) {
            return;
        }

        $owned = FinStockItem::query()->whereNotNull('owner_party_id')
            ->when($clients !== [], fn ($q) => $q->whereNotIn('owner_party_id', $clients))
            ->select('id');

        $query->whereNotIn($itemColumn, $owned);
    }

    public function mayView(FinStockItem $item, ?User $user = null): bool
    {
        if ($item->owner_party_id === null) {
            return true;
        }
        $clients = $this->clientsFor($user ?? Auth::user());

        return $clients === null || in_array((int) $item->owner_party_id, $clients, true);
    }

    public function grant(int $userId, int $partyId): ClientAccess
    {
        return ClientAccess::query()->firstOrCreate(['user_id' => $userId, 'party_id' => $partyId], [
            'granted_by' => Auth::id(),
            'company_id' => \Illuminate\Support\Facades\DB::table('fin_parties')->where('id', $partyId)->value('company_id'),
        ]);
    }

    public function revoke(int $userId, int $partyId): void
    {
        ClientAccess::query()->where('user_id', $userId)->where('party_id', $partyId)->delete();
    }
}
