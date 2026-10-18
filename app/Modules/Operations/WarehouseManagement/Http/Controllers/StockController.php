<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Services\ClientVisibility;
use App\Modules\Operations\WarehouseManagement\Services\StockAvailability;
use App\Modules\Operations\WarehouseManagement\Services\StockHistory;
use App\Modules\Operations\WarehouseManagement\Support\StockStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-021/022/025: stock by state, the availability figures, and the movement history. */
class StockController extends Controller
{
    public function __construct(
        private readonly StockAvailability $availability,
        private readonly StockHistory $history,
        private readonly ClientVisibility $clients,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;
        $q = trim((string) $request->input('q', ''));

        $items = FinStockItem::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")))
            ->whereIn('id', DB::table('fin_stock_positions')->where('qty', '!=', 0)
                ->when($warehouse, fn ($p) => $p->where('warehouse_id', $warehouse->id))->select('item_id'))
            ->tap(fn ($query) => $this->clients->scope($query))
            ->orderBy('code')->paginate(50)->withQueryString();

        $rows = collect($this->availability->rows(collect($items->items())->pluck('id')->all(), $warehouse?->id))
            ->groupBy('item_id');
        $sum = fn ($set, $k) => round($set->sum($k), 4);

        return Inertia::render('Modules/Warehouse/Stock/Index', [
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'filters' => ['warehouse' => $warehouse?->uuid, 'q' => $q],
            'items' => $items->through(function (FinStockItem $i) use ($rows, $sum) {
                $set = $rows[$i->id] ?? collect();

                return ['id' => $i->uuid, 'code' => $i->code, 'name' => $i->name, 'uom' => $i->uom, 'status' => $i->status,
                    'owned_by_client' => $i->owner_party_id !== null]
                    + collect(['physical', 'held', 'in_transit', 'available_on_hand', 'reserved', 'allocated_not_picked',
                        'available_to_promise', 'expected_receipts', 'backordered'])
                        ->mapWithKeys(fn ($k) => [$k => $k === 'expected_receipts' ? (float) ($set->first()['expected_receipts'] ?? 0) : $sum($set, $k)])->all();
            }),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $item = FinStockItem::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $item);
        abort_unless($this->clients->mayView($item), 403, 'This item belongs to a client you have not been granted.');

        $warehouses = FinWarehouse::query()->pluck('code', 'id');

        return Inertia::render('Modules/Warehouse/Stock/Show', [
            'item' => ['id' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'uom' => $item->uom, 'status' => $item->status,
                'is_valued' => $item->isValued(), 'owner' => $item->owner_party_id ? DB::table('fin_parties')->where('id', $item->owner_party_id)->value('name') : null],
            'availability' => tap($this->availability->forItem($item), function (&$a) use ($warehouses) {
                $a['by_warehouse'] = array_map(fn ($r) => $r + ['warehouse' => $warehouses[$r['warehouse_id']] ?? null], $a['by_warehouse']);
            }),
            'positions' => FinStockPosition::query()->where('item_id', $item->id)->where('qty', '!=', 0)
                ->with(['bin:id,code,kind', 'lot:id,lot_number,expires_on', 'warehouse:id,code'])->orderBy('warehouse_id')->orderBy('bin_id')->get()
                ->map(fn (FinStockPosition $p) => ['warehouse' => $p->warehouse?->code, 'bin' => $p->bin?->code, 'bin_kind' => $p->bin?->kind,
                    'lot' => $p->lot?->lot_number, 'expires_on' => $p->lot?->expires_on?->toDateString(),
                    'expired' => (bool) $p->lot?->isExpiredOn(), 'status' => $p->status, 'qty' => (float) $p->qty]),
            'reconciliation' => $this->history->reconcile($item->id),
            'movements' => $this->history->movements(['item_id' => $item->id], 25),
            'states' => StockStates::table(),
        ]);
    }

    public function movements(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $resolve = fn (string $table, ?string $uuid) => $uuid ? DB::table($table)->where('uuid', $uuid)->value('id') : null;
        $filters = [
            'item_id' => $resolve('fin_stock_items', $request->input('item')),
            'warehouse_id' => $resolve('fin_warehouses', $request->input('warehouse')),
            'type' => $request->input('type'), 'from' => $request->input('from'), 'to' => $request->input('to'),
            'reason_code' => $request->input('reason_code'),
        ];

        return Inertia::render('Modules/Warehouse/Stock/Movements', [
            'movements' => $this->history->movements($filters),
            'filters' => $request->only(['item', 'warehouse', 'type', 'from', 'to', 'reason_code']),
            'warehouses' => FinWarehouse::query()->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'types' => DB::table('fin_stock_movements')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /** WMS-021: what every state counts towards, stated rather than implied. */
    public function states(): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Stock/States', ['states' => StockStates::table()]);
    }
}
