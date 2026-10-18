<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ProductionMaterials;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** WMS-160..165: what production needs from the warehouse, and what it hands back. */
class ProductionController extends Controller
{
    use RefusesPolitely;

    public function board(Request $request, ProductionMaterials $materials): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Production/Materials', $materials->board() + [
            'can' => ['orders' => $request->user()->can(RoleCatalog::MANAGE_ORDERS), 'move' => $request->user()->can(RoleCatalog::MOVE_STOCK)],
        ]);
    }

    /** Raise the warehouse pick for a production order's outstanding components (WMS-161). */
    public function pick(Request $request, string $uuid, FulfilmentOrders $orders): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_ORDERS), 403);
        $po = ProductionOrder::query()->where('uuid', $uuid)->firstOrFail();

        return $this->attempt(function () use ($po, $orders, $request) {
            $warehouse = FinWarehouse::query()->findOrFail($po->component_warehouse_id ?: $po->warehouse_id);
            if (! $warehouse->is_wms_managed) {
                throw new RuntimeException("{$warehouse->code} is not run by the warehouse module, so its materials are issued from Manufacturing directly.");
            }
            $orders->fromProductionOrder($po, $warehouse, $request->user());
        }, "Pick raised for {$po->number}.");
    }

    /** Send finished goods that reached stock without a location to a put-away task (WMS-163). */
    public function putAway(Request $request, string $position, PutawayTasks $tasks): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $p = FinStockPosition::query()->whereKey($position)->whereNull('bin_id')->where('qty', '>', 0)->firstOrFail();

        return $this->attempt(fn () => $tasks->create(FinWarehouse::query()->findOrFail($p->warehouse_id), FinStockItem::query()->findOrFail($p->item_id),
            (float) $p->qty, ['lot_id' => $p->lot_id, 'stock_status' => $p->status, 'source_type' => 'PRODUCTION_ORDER']), 'Put-away task created.');
    }
}
