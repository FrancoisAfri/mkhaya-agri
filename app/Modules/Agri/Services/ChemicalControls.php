<?php

namespace App\Modules\Agri\Services;

use App\Modules\HR\Hr\Attendance\Services\HrSources;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §5.3: regulated agricultural chemicals are kept only where their storage class is
 * allowed and nothing incompatible is held, and are issued or applied only by people who are
 * authorised — named, or holding the competency (an applicator's licence) the product requires —
 * while the product's registration is current. Each refusal says exactly what is missing.
 */
class ChemicalControls
{
    public function __construct(private readonly HrSources $hr) {}

    public function product(FinStockItem|int $item): ?ChemicalProduct
    {
        $id = $item instanceof FinStockItem ? $item->id : $item;

        return ChemicalProduct::withoutGlobalScopes()->where('stock_item_id', $id)->first();
    }

    /** May this item go into this store? (A receipt, a transfer in.) */
    public function assertStorable(FinStockItem $item, ?int $warehouseId): void
    {
        $chem = $this->product($item);
        if (! $chem || ! $warehouseId) {
            return;
        }
        $store = FarmStore::withoutGlobalScopes()->where('warehouse_id', $warehouseId)->first();
        if (! $store) {
            return;   // not a farm store: the warehouse module's own rules apply
        }
        $allowed = $store->allowed_storage_classes ?? [];
        if (! in_array($chem->storage_class, $allowed, true)) {
            throw ValidationException::withMessages(['store' => "{$item->name} is a {$chem->storage_class} chemical; {$store->name} is not set up to hold that class"
                .($allowed ? ' (it takes '.implode(', ', $allowed).').' : ' (it holds no chemicals).')]);
        }
        // Nothing already in the store may be incompatible with it, nor it with anything there.
        $present = DB::table('fin_stock_positions as p')->join('chemical_products as c', 'c.stock_item_id', '=', 'p.item_id')
            ->where('p.warehouse_id', $warehouseId)->where('p.qty', '>', 0)->where('p.item_id', '!=', $item->id)
            ->get(['c.storage_class', 'c.incompatible_with', 'c.stock_item_id']);
        foreach ($present as $p) {
            $theirs = json_decode($p->incompatible_with ?? '[]', true) ?: [];
            if (in_array($p->storage_class, $chem->incompatible_with ?? [], true) || in_array($chem->storage_class, $theirs, true)) {
                $other = FinStockItem::withoutGlobalScopes()->whereKey($p->stock_item_id)->value('name');
                throw ValidationException::withMessages(['store' => "{$item->name} ({$chem->storage_class}) may not be stored with {$other} ({$p->storage_class}) in {$store->name}."]);
            }
        }
    }

    /**
     * May this person issue or apply this item? A restricted product needs the person to be named on
     * it or to hold its competency, and every chemical needs a current registration. A locked store
     * needs an authorised person for whatever leaves it.
     */
    public function assertMayUse(FinStockItem $item, ?User $user, ?int $warehouseId = null): void
    {
        $chem = $this->product($item);
        $store = $warehouseId ? FarmStore::withoutGlobalScopes()->where('warehouse_id', $warehouseId)->first() : null;
        if (! $chem && ! $store?->restricted) {
            return;
        }
        if ($chem?->registration_expires_on && $chem->registration_expires_on->isPast()) {
            throw ValidationException::withMessages(['item' => "{$item->name}'s registration ({$chem->registration_no}) expired on {$chem->registration_expires_on->toDateString()}; it may not be used."]);
        }
        if (! ($chem?->restricted || $store?->restricted)) {
            return;
        }
        if (! $user) {
            throw ValidationException::withMessages(['item' => "{$item->name} is restricted: an authorised person must issue it."]);
        }
        if (in_array($user->id, array_map('intval', $chem?->authorised_user_ids ?? []), true) || $user->is_platform_admin) {
            return;
        }
        if ($chem?->competency_code) {
            $person = DB::table('hr_people')->where('user_id', $user->id)->value('id');
            $held = $person ? $this->hr->holds((int) $person, $chem->competency_code, now()) : ['ok' => false, 'reason' => 'No HR record.'];
            if ($held['ok']) {
                return;
            }
            throw ValidationException::withMessages(['item' => "{$item->name} is restricted to people holding {$chem->competency_code}. {$user->name}: {$held['reason']}"]);
        }
        throw ValidationException::withMessages(['item' => "{$item->name}".($store?->restricted ? " in {$store->name}" : '').' is restricted and you are not authorised to issue it.']);
    }
}
