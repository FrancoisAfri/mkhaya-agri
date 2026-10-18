<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\Batches;
use App\Modules\Operations\WarehouseManagement\Services\ClientVisibility;
use App\Modules\Operations\WarehouseManagement\Services\ExpiryWatch;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-090 batches, WMS-091 serial numbers, WMS-092 rotation overrides, WMS-093 expiry. */
class BatchController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, Batches $batches): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $f = $request->only(['q', 'kind', 'expiring_within']) + ['in_stock' => $request->boolean('in_stock', true)];

        return Inertia::render('Modules/Warehouse/Batches/Index', [
            'batches' => $batches->register($f),
            'filters' => $f,
        ]);
    }

    public function show(Request $request, string $uuid, Batches $batches, ClientVisibility $clients): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $lot = FinStockLot::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($clients->mayView(FinStockItem::query()->findOrFail($lot->item_id)), 403);

        return Inertia::render('Modules/Warehouse/Batches/Show', $batches->detail($lot));
    }

    /** Scan or type a serial number: straight to it, or a choice where two products share the number. */
    public function serial(Request $request): RedirectResponse|Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $code = trim((string) $request->input('q', ''));
        $found = $code === '' ? collect() : FinStockLot::query()->where('lot_number', $code)->with('item:id,code,name')->limit(20)->get();
        if ($found->count() === 1) {
            return redirect()->route('warehouse.batches.show', $found->first()->uuid);
        }

        return Inertia::render('Modules/Warehouse/Batches/Index', [
            'batches' => app(Batches::class)->register(['q' => $code, 'in_stock' => false]),
            'filters' => ['q' => $code, 'in_stock' => false],
            'not_found' => $code !== '' && $found->isEmpty(),
        ]);
    }

    public function expiry(Request $request, ExpiryWatch $watch): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;
        $within = (int) $request->input('within', 120);
        $rows = $watch->atRisk($warehouse?->id, $within);

        return Inertia::render('Modules/Warehouse/Batches/Expiry', [
            'rows' => $rows,
            'totals' => ['lots' => count($rows), 'qty' => round(array_sum(array_column($rows, 'qty')), 4),
                'at_risk_qty' => round(array_sum(array_column($rows, 'at_risk_qty')), 4), 'exposure' => round(array_sum(array_column($rows, 'exposure')), 2),
                'expired' => count(array_filter($rows, fn ($r) => $r['days_left'] < 0))],
            'filters' => ['warehouse' => $warehouse?->uuid, 'within' => $within],
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
        ]);
    }

    /** Where an order line could be allocated from, for an override: every free place, in no particular rotation. */
    public function options(Request $request, string $uuid)
    {
        abort_unless($request->user()->can(RoleCatalog::OVERRIDE_ROTATION), 403);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();
        $line = FulfilmentLine::query()->where('order_id', $order->id)->findOrFail($request->integer('line_id'));

        return response()->json(\Illuminate\Support\Facades\DB::table('fin_stock_positions as p')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->where('p.item_id', $line->item_id)->where('p.warehouse_id', $order->warehouse_id)->where('p.status', 'AVAILABLE')->where('p.qty', '>', 0)
            ->orderByRaw('l.expires_on ASC NULLS LAST')->get(['p.bin_id', 'p.lot_id', 'p.qty', 'b.code as bin', 'l.lot_number as lot', 'l.expires_on'])
            ->map(fn ($r) => ['bin_id' => $r->bin_id, 'lot_id' => $r->lot_id, 'qty' => (float) $r->qty,
                'title' => trim(($r->bin ?? '—').' · '.($r->lot ?? '—').($r->expires_on ? " (exp {$r->expires_on})" : '')." · {$r->qty}")]));
    }

    public function override(Request $request, string $uuid, Allocator $allocator): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::OVERRIDE_ROTATION), 403);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['line_id' => ['required', 'integer'], 'bin_id' => ['nullable', 'integer'], 'lot_id' => ['nullable', 'integer'],
            'qty' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:500']]);
        $line = FulfilmentLine::query()->where('order_id', $order->id)->findOrFail($data['line_id']);
        if (! empty($data['bin_id'])) {
            FinStockBin::query()->where('warehouse_id', $order->warehouse_id)->findOrFail($data['bin_id']);
        }

        return $this->attempt(fn () => $allocator->override($line, $data['bin_id'] ?? null, $data['lot_id'] ?? null, (float) $data['qty'], $data['reason'], $request->user()),
            'Allocated by override.');
    }
}
