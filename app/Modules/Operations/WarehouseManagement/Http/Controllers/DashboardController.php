<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\LocationChange;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module's landing page. Reports what is configured and what needs attention, distinguishing
 * "none configured" from "configured and quiet" -- a warehouse with no bins cannot be put away
 * into, and a dashboard showing 0 for both hides the thing somebody has to fix.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', FinWarehouse::class);

        $visible = app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAccess::class)->visibleIds(request()->user());
        $warehouses = FinWarehouse::query()->where('is_active', true)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->withCount(['bins' => fn ($q) => $q->where('level', 'BIN')->where('is_active', true)])
            ->orderBy('code')->get();

        $stock = DB::table('fin_stock_positions')->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->groupBy('warehouse_id')
            ->selectRaw("warehouse_id, SUM(qty) AS qty, SUM(CASE WHEN bin_id IS NULL THEN qty ELSE 0 END) AS unbinned, "
                ."SUM(CASE WHEN status <> 'AVAILABLE' THEN qty ELSE 0 END) AS held, COUNT(DISTINCT item_id) AS items")
            ->get()->keyBy('warehouse_id');

        // Raw counts are named by company: a platform administrator has no tenant stamped for RLS.
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? request()->user()?->company_id;
        $t = fn (string $table) => DB::table($table)->when($cid, fn ($q) => $q->where('company_id', $cid));

        return Inertia::render('Modules/Warehouse/Dashboard', [
            'warehouses' => $warehouses->map(fn (FinWarehouse $w) => [
                'id' => $w->uuid,
                'code' => $w->code,
                'name' => $w->name,
                'type' => $w->warehouse_type,
                'is_wms_managed' => $w->is_wms_managed,
                'is_default' => $w->is_default,
                'bins' => $w->bins_count,
                'qty' => (float) ($stock[$w->id]->qty ?? 0),
                'unbinned' => (float) ($stock[$w->id]->unbinned ?? 0),
                'held' => (float) ($stock[$w->id]->held ?? 0),
                'items' => (int) ($stock[$w->id]->items ?? 0),
            ]),
            'attention' => [
                'blocked' => FinStockBin::query()->where('level', 'BIN')->where('status', FinStockBin::BLOCKED)->where('is_active', true)->count(),
                'restricted' => FinStockBin::query()->where('level', 'BIN')->where('status', FinStockBin::RESTRICTED)->where('is_active', true)->count(),
                'warehouses_without_bins' => $warehouses->where('bins_count', 0)->count(),
            ],
            // Today's queues: each figure links to the screen where the work is done.
            'queues' => [
                ['key' => 'expected_overdue', 'route' => 'warehouse.inbound.expected', 'params' => ['overdue' => 1], 'tone' => 'error',
                    'n' => DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')->when($cid, fn ($q) => $q->where('o.company_id', $cid))
                        ->whereNull('o.deleted_at')->whereIn('o.status', \App\Modules\Operations\WarehouseManagement\Services\Receiving::RECEIVABLE)
                        ->whereNotNull('i.stock_item_id')->whereRaw('i.quantity > COALESCE(i.received_qty, 0)')
                        ->whereDate('o.expected_delivery', '<', now()->toDateString())->count()],
                ['key' => 'receipt_discrepancies', 'route' => 'warehouse.inbound.receipts.index', 'params' => ['discrepancy' => 1], 'tone' => 'warning',
                    'n' => $t('goods_received_notes')->where('discrepancy_status', 'OPEN')->count()],
                ['key' => 'receipts_pending', 'route' => 'warehouse.inbound.receipts.index', 'params' => ['pending' => 1], 'tone' => 'warning',
                    'n' => $t('goods_received_notes')->where('approval_status', 'PENDING')->count()],
                ['key' => 'putaway_open', 'route' => 'warehouse.floor.putaway.index', 'params' => [], 'tone' => 'primary',
                    'n' => $t('wms_putaway_tasks')->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count()],
                ['key' => 'putaway_exceptions', 'route' => 'warehouse.floor.putaway.index', 'params' => ['view' => 'exceptions'], 'tone' => 'error',
                    'n' => $t('wms_putaway_tasks')->whereIn('status', ['FAILED', 'PARTIAL'])->count()],
                ['key' => 'replenishment', 'route' => 'warehouse.floor.replenishment.index', 'params' => [], 'tone' => 'primary',
                    'n' => $t('wms_replenishment_tasks')->whereIn('status', ['OPEN', 'PARTIAL', 'FAILED'])->count()],
                ['key' => 'transfers_in_transit', 'route' => 'warehouse.floor.transfers.index', 'params' => ['status' => 'DISPATCHED'], 'tone' => 'primary',
                    'n' => $t('wms_transfer_orders')->whereIn('status', ['DISPATCHED', 'PARTIALLY_RECEIVED'])->count()],
                ['key' => 'orders_new', 'route' => 'warehouse.outbound.orders.index', 'params' => ['view' => 'new'], 'tone' => 'primary',
                    'n' => $t('wms_orders')->where('status', 'NEW')->count()],
                ['key' => 'orders_short', 'route' => 'warehouse.outbound.orders.index', 'params' => ['view' => 'short'], 'tone' => 'error',
                    'n' => $t('wms_orders')->whereIn('status', ['BACKORDERED', 'PARTIALLY_ALLOCATED'])->count()],
                ['key' => 'picks_open', 'route' => 'warehouse.outbound.waves.index', 'params' => [], 'tone' => 'primary',
                    'n' => $t('wms_pick_tasks')->where('status', 'OPEN')->count()],
                ['key' => 'orders_at_risk', 'route' => 'warehouse.outbound.sla', 'params' => [], 'tone' => 'error',
                    'n' => $t('wms_orders')->whereIn('status', \App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder::LIVE)->whereNotNull('sla_at_risk_at')->count()],
                ['key' => 'adjustments_pending', 'route' => 'warehouse.stock.adjustments.index', 'params' => ['status' => 'PENDING_APPROVAL'], 'tone' => 'warning',
                    'n' => $t('wms_stock_adjustments')->where('status', 'PENDING_APPROVAL')->count()],
            ],
            // WMS-180: the rest of the day at a glance -- what can be promised, what left, how the counts stand, and what is critical.
            'today' => [
                'available' => (float) $t('fin_stock_positions')->whereIn('warehouse_id', $warehouses->where('is_wms_managed', true)->pluck('id'))->where('status', 'AVAILABLE')->sum('qty')
                    - (float) $t('fin_stock_reservations')->whereIn('warehouse_id', $warehouses->where('is_wms_managed', true)->pluck('id'))->where('status', 'OPEN')->sum(DB::raw('qty - qty_consumed')),
                'expected_today' => DB::table('purchase_orders')->when($cid, fn ($q) => $q->where('company_id', $cid))->whereNull('deleted_at')
                    ->whereIn('status', \App\Modules\Operations\WarehouseManagement\Services\Receiving::RECEIVABLE)->whereDate('expected_delivery', now()->toDateString())->count(),
                'dispatched_today' => $t('wms_shipments')->whereDate('dispatched_at', now()->toDateString())->count(),
                'delivered_today' => $t('wms_shipments')->whereDate('delivered_at', now()->toDateString())->count(),
                'counts' => (function () use ($t) {
                    $open = $t('wms_counts')->whereIn('status', ['OPEN', 'COUNTING', 'REVIEW'])->pluck('id');
                    $lines = DB::table('wms_count_lines')->whereIn('count_id', $open)->selectRaw("COUNT(*) AS n, SUM(CASE WHEN status <> 'OPEN' THEN 1 ELSE 0 END) AS done")->first();

                    return ['open' => $open->count(), 'lines' => (int) ($lines->n ?? 0), 'counted' => (int) ($lines->done ?? 0)];
                })(),
            ],
            'critical' => [
                ['key' => 'findings', 'route' => 'warehouse.protection.index', 'n' => $t('mfg_loss_findings')->whereNotNull('warehouse_id')
                    ->whereIn('status', \App\Modules\Operations\Manufacturing\Models\LossFinding::OPEN)->whereIn('priority', ['URGENT', 'HIGH'])->count()],
                ['key' => 'recalls', 'route' => 'warehouse.recalls.index', 'n' => $t('mfg_recalls')->where('status', '<>', 'CLOSED')->count()],
                ['key' => 'expired', 'route' => 'warehouse.batches.expiry', 'n' => $t('fin_stock_positions')->where('qty', '>', 0)->where('status', 'AVAILABLE')
                    ->whereIn('lot_id', DB::table('fin_stock_lots')->whereDate('expires_on', '<', now()->toDateString())->select('id'))->count()],
                ['key' => 'dead_letters', 'route' => 'warehouse.integrations.events', 'n' => $t('wms_outbox')->whereNotNull('failed_at')->count()],
                ['key' => 'integration_failures', 'route' => 'warehouse.integrations.systems', 'n' => $t('mfg_integration_events')->where('event_type', 'like', 'WMS_%')->whereIn('status', ['FAILED', 'DEAD'])->count()],
                ['key' => 'offline_conflicts', 'route' => 'warehouse.mobile.conflicts', 'n' => $t('wms_offline_actions')->where('status', 'CONFLICT')->count()],
            ],
            'recent_changes' => LocationChange::query()->with(['warehouse:id,code', 'bin:id,code'])
                ->latest('created_at')->limit(10)->get()
                ->map(fn (LocationChange $c) => [
                    'action' => $c->action,
                    'warehouse' => $c->warehouse?->code,
                    'location' => $c->bin?->code,
                    'reason' => $c->reason,
                    'at' => $c->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
