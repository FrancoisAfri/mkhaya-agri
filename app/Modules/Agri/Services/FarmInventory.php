<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AgriItemSetting;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use App\Modules\Operations\WarehouseManagement\Services\TransferOrders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §5.1–5.2 for a farm: its stores (a farm has more than one — silos, cold rooms, the
 * chemical store, the fuel tank), what each item is to the farm, transfers between stores with
 * dispatch and receipt confirmed separately, stock put on hold (quarantined, damaged, rejected),
 * reorder suggestions from actual use and lead time, and the value of what each store holds.
 *
 * All of it runs on the shared inventory: the warehouse module's transfer orders, the stock
 * ledger's movements and positions. Nothing here rewrites a movement — every change is a new one.
 */
class FarmInventory
{
    public function __construct(private readonly StockLedger $ledger, private readonly FarmStores $stores) {}

    /** The farm a warehouse belongs to, and the dimensions its postings carry (for receipts posted elsewhere). */
    public static function dimensionsFor(FinWarehouse $warehouse): array
    {
        $farmId = FarmStore::withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->value('farm_id')
            ?? Farm::withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->value('id');
        $farm = $farmId ? Farm::withoutGlobalScopes()->find($farmId) : null;

        return $farm ? AgriLedger::dimensions($farm, null, null, 'stock') : [];
    }

    /**
     * The warehouse module counts in units the company has set up; a farm item's unit ("bag", "l")
     * is set up the first time it is needed — mass, volume or a count, as its code says.
     */
    public static function ensureUnit(FinStockItem $item): void
    {
        $code = trim((string) ($item->uom ?: 'ea'));
        if (DB::table('mfg_uoms')->where('company_id', $item->company_id)->whereRaw('lower(code) = ?', [mb_strtolower($code)])->exists()) {
            return;
        }
        $dimension = match (mb_strtolower($code)) {
            'kg', 'g', 't', 'ton', 'tonne' => 'MASS', 'l', 'ml', 'kl', 'm3' => 'VOLUME', 'm', 'km' => 'LENGTH', 'ha', 'm2' => 'AREA', default => 'COUNT',
        };
        DB::table('mfg_uoms')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $item->company_id, 'code' => $code, 'name' => $code,
            'dimension' => $dimension, 'is_base' => true, 'factor_to_base' => 1, 'decimal_places' => $dimension === 'COUNT' ? 0 : 3, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return Collection<int, FarmStore> the main store first */
    public function storesOf(Farm $farm): Collection
    {
        $this->stores->storeFor($farm);   // the main store always exists

        return FarmStore::query()->where('farm_id', $farm->id)->where('is_active', true)->orderByRaw("kind = 'main' desc")->orderBy('name')->get();
    }

    public function addStore(Farm $farm, array $data): FarmStore
    {
        if (! in_array($data['kind'], FarmStore::KINDS, true) || $data['kind'] === 'main') {
            throw ValidationException::withMessages(['kind' => 'Choose what kind of store it is.']);
        }
        $this->stores->storeFor($farm);

        return DB::transaction(function () use ($farm, $data) {
            $n = FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->count() + 1;
            $wh = FinWarehouse::create(['company_id' => $farm->company_id, 'code' => Str::limit('FARM-'.strtoupper($farm->code)."-{$n}", 40, ''),
                'name' => "{$farm->name}: {$data['name']}", 'address' => $farm->location, 'is_active' => true, 'is_default' => false,
                'policies' => FarmStores::WAREHOUSE_POLICIES]);

            return FarmStore::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'warehouse_id' => $wh->id, 'kind' => $data['kind'],
                'name' => $data['name'], 'capacity' => $data['capacity'] ?? null, 'capacity_unit' => $data['capacity_unit'] ?? null,
                'allowed_storage_classes' => $data['allowed_storage_classes'] ?? null, 'restricted' => (bool) ($data['restricted'] ?? false)]);
        });
    }

    public function itemSetting(FinStockItem $item, array $data): AgriItemSetting
    {
        if (! in_array($data['category'], AgriItemSetting::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Choose a category.']);
        }

        return AgriItemSetting::query()->updateOrCreate(['company_id' => $item->company_id, 'stock_item_id' => $item->id],
            array_intersect_key($data, array_flip(['category', 'preferred_supplier_id', 'default_store_id', 'lot_tracked', 'serial_tracked'])));
    }

    // ── Transfers ────────────────────────────────────────────────────────────────────────────

    /**
     * Stock from one farm store to another, through the warehouse module's transfer order: drafted
     * and approved now, dispatched by the sending store, received by the receiving one. A chemical
     * only goes where its storage class is allowed.
     *
     * @param  list<array{item_id: int, qty: float, lot_id?: ?int}>  $lines
     */
    public function transfer(FarmStore $from, FarmStore $to, array $lines, User $actor, ?string $reason = null): TransferOrder
    {
        if ((int) $from->company_id !== (int) $to->company_id || $from->id === $to->id) {
            throw ValidationException::withMessages(['to_store_id' => 'Choose a different store of the same organisation.']);
        }
        $chem = app(ChemicalControls::class);
        foreach ($lines as $l) {
            $item = FinStockItem::query()->findOrFail($l['item_id']);
            $chem->assertStorable($item, (int) $to->warehouse_id);
            $chem->assertMayUse($item, $actor, (int) $from->warehouse_id);
        }
        $orders = app(TransferOrders::class);
        try {
            $order = $orders->draft(FinWarehouse::query()->findOrFail($from->warehouse_id), FinWarehouse::query()->findOrFail($to->warehouse_id), $lines, $actor,
                ['reason' => $reason, 'source' => 'agri']);

            return $orders->approve($order, $actor);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
    }

    /** The sending store confirms what left. */
    public function dispatch(TransferOrder $order, User $actor): TransferOrder
    {
        $orders = app(TransferOrders::class);
        try {
            if ($order->status === 'APPROVED') {
                $order = $orders->pick($order, $order->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->qty])->all(), $actor);
            }

            return $orders->dispatch($order->fresh('lines'), $actor);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }
    }

    /** The receiving store confirms what arrived (less, if some went missing on the way). @param array<int, float> $received line id => qty */
    public function receive(TransferOrder $order, array $received, User $actor): TransferOrder
    {
        $bin = $this->storageBin((int) $order->to_warehouse_id);
        try {
            return app(TransferOrders::class)->receive($order, collect($received)->map(fn ($q) => ['qty' => (float) $q, 'to_bin_id' => $bin->id])->all(), $actor);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }
    }

    /** A farm store has no racking: one storage bin holds what arrives by transfer. */
    private function storageBin(int $warehouseId): \App\Modules\Finance\Accounting\Models\FinStockBin
    {
        $wh = FinWarehouse::query()->findOrFail($warehouseId);

        return \App\Modules\Finance\Accounting\Models\FinStockBin::query()->firstOrCreate(['warehouse_id' => $wh->id, 'code' => 'STORE'],
            ['company_id' => $wh->company_id, 'name' => 'Store', 'kind' => 'STORAGE', 'level' => 'BIN', 'is_active' => true, 'status' => 'OPEN']);
    }

    // ── Holding stock back ───────────────────────────────────────────────────────────────────

    /** Put usable stock on hold — quarantined, damaged, rejected — or release it back. A movement each time, never an edit. */
    public function changeStatus(FarmStore $store, FinStockItem $item, float $qty, string $from, string $to, string $reason, User $actor): void
    {
        $states = [FinStockPosition::AVAILABLE, FinStockPosition::QUARANTINE, 'DAMAGED', 'REJECTED'];
        if (! in_array($from, $states, true) || ! in_array($to, $states, true) || $from === $to) {
            throw ValidationException::withMessages(['status' => 'Choose where the stock is and where it goes.']);
        }
        try {
            $this->ledger->changeStatus($item, FinWarehouse::query()->findOrFail($store->warehouse_id), $qty, $from, $to,
                ['reason' => $reason, 'source_type' => 'agri_store', 'source_id' => $store->id, 'idempotency_key' => 'agri-hold-'.Str::uuid()]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        }
    }

    // ── Reorder and value ────────────────────────────────────────────────────────────────────

    /**
     * What to order, item by item, from the last 90 days' use: when what is in the farm's stores
     * plus what is on order will not last the supplier's lead time plus a fortnight, suggest enough
     * for the lead time plus a month. Each row shows the numbers it came from.
     *
     * @return list<array<string, mixed>>
     */
    /** @param  ?int  $horizonDays  look this far ahead instead of the lead time plus two weeks (the assistant's "before harvest") */
    public function reorderSuggestions(Farm $farm, ?int $horizonDays = null): array
    {
        $whs = $this->storesOf($farm)->pluck('warehouse_id')->all();
        $since = today()->subDays(90);
        $used = DB::table('fin_stock_movements')->whereIn('warehouse_id', $whs)->where('qty', '<', 0)->whereIn('type', ['issue', 'production_issue', 'dispatch'])
            ->where('movement_date', '>=', $since)->whereNull('reversal_of_id')->groupBy('item_id')->selectRaw('item_id, -SUM(qty) as used')->pluck('used', 'item_id');
        $rows = [];
        foreach (FinStockItem::query()->whereIn('id', $used->keys())->get() as $item) {
            $daily = (float) $used[$item->id] / 90;
            $have = array_sum(array_map(fn ($w) => $this->ledger->available($item, (int) $w), $whs));
            $onOrder = (float) DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
                ->where('i.stock_item_id', $item->id)->whereNull('o.deleted_at')->whereNotIn('o.status', ['draft', 'received', 'closed', 'cancelled', 'rejected'])
                ->selectRaw('COALESCE(SUM(i.quantity - COALESCE(i.received_qty, 0)), 0) as q')->value('q');
            $lead = (int) (DB::table('wms_supplier_terms')->where('stock_item_id', $item->id)->min('lead_time_days')
                ?? DB::table('wms_item_profiles')->where('stock_item_id', $item->id)->value('lead_time_days') ?? 14);
            $daysLeft = $daily > 0 ? ($have + $onOrder) / $daily : null;
            $window = max($lead + 14, (int) $horizonDays);
            if ($daysLeft !== null && $daysLeft < $window) {
                $rows[] = ['item_id' => $item->id, 'item' => $item->name, 'uom' => $item->uom, 'used_90d' => round((float) $used[$item->id], 2),
                    'per_day' => round($daily, 3), 'available' => round($have, 2), 'on_order' => round($onOrder, 2), 'lead_time_days' => $lead,
                    'days_left' => round($daysLeft, 1), 'suggest' => round(max(0, $daily * max($lead + 30, (int) $horizonDays) - $have - $onOrder), 2),
                    'order_by' => today()->addDays((int) max(0, floor($daysLeft - $lead)))->toDateString()];
            }
        }

        return collect($rows)->sortBy('days_left')->values()->all();
    }

    /** What each store holds, by state, at the items' carrying cost — set beside the ledger's inventory figure. */
    public function valuation(Farm $farm): array
    {
        $stores = $this->storesOf($farm);
        $rows = DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->whereIn('p.warehouse_id', $stores->pluck('warehouse_id'))->where('p.qty', '!=', 0)
            ->groupBy('p.warehouse_id', 'p.status')->selectRaw('p.warehouse_id, p.status, SUM(p.qty * COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost, 0)) as value, COUNT(DISTINCT p.item_id) as items')->get();

        return $stores->map(fn (FarmStore $s) => ['id' => $s->uuid, 'name' => $s->name, 'kind' => $s->kind,
            'value' => round((float) $rows->where('warehouse_id', $s->warehouse_id)->sum('value'), 2),
            'by_status' => $rows->where('warehouse_id', $s->warehouse_id)->mapWithKeys(fn ($r) => [$r->status => round((float) $r->value, 2)])->all(),
            'items' => (int) $rows->where('warehouse_id', $s->warehouse_id)->sum('items')])->values()->all();
    }
}
