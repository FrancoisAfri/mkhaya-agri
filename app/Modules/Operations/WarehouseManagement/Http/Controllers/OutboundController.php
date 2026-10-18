<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PackageContent;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\PickWave;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\Dispatching;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentSla;
use App\Modules\Operations\WarehouseManagement\Services\Packing;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-060 to WMS-066: orders, allocation, picking, packing, dispatch and service levels. */
class OutboundController extends Controller
{
    use RefusesPolitely;

    public function __construct(private readonly FulfilmentOrders $orders) {}

    // ── Orders ───────────────────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $statuses = match ($request->input('view', 'open')) {
            'new' => ['NEW'],
            'short' => ['BACKORDERED', 'PARTIALLY_ALLOCATED'],
            'done' => ['DISPATCHED', 'DELIVERED', 'CANCELLED'],
            default => array_merge(['NEW'], FulfilmentOrder::LIVE),
        };
        $list = FulfilmentOrder::query()->with(['warehouse:id,code', 'lines'])->whereIn('status', $statuses)
            ->when($request->filled('warehouse'), fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('uuid', $request->input('warehouse'))))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', '%'.$request->input('q').'%')
                ->orWhere('external_ref', 'ilike', '%'.$request->input('q').'%')->orWhere('customer_name', 'ilike', '%'.$request->input('q').'%')))
            ->orderByRaw('sla_at_risk_at IS NULL')->orderByDesc('priority')->orderByRaw('sla_due_at NULLS LAST')->orderByDesc('id')
            ->paginate(40)->withQueryString();

        return Inertia::render('Modules/Warehouse/Outbound/Orders', [
            'orders' => $list->through(fn (FulfilmentOrder $o) => $this->summary($o)),
            'filters' => ['view' => $request->input('view', 'open'), 'warehouse' => $request->input('warehouse'), 'q' => $request->input('q')],
            'warehouses' => $this->warehouseOptions(),
            'sales_orders' => FinSalesOrder::query()->whereNotIn('status', ['invoiced', 'cancelled', 'delivered'])->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'party_id'])->map(fn ($s) => ['id' => $s->id, 'title' => $s->number]),
            'production_orders' => ProductionOrder::query()->whereIn('status', [ProductionOrder::RELEASED, ProductionOrder::IN_PRODUCTION])->orderByDesc('id')->limit(200)
                ->get(['id', 'number'])->map(fn ($p) => ['id' => $p->id, 'title' => $p->number]),
            'counts' => FulfilmentOrder::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
            'can' => $this->can($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_ORDERS), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'kind' => ['required', Rule::in(['SALES_ORDER', 'MANUFACTURING', 'INTERNAL'])],
            'sales_order_id' => ['required_if:kind,SALES_ORDER', 'nullable', 'integer'], 'production_order_id' => ['required_if:kind,MANUFACTURING', 'nullable', 'integer'],
            'reference' => ['required_if:kind,INTERNAL', 'nullable', 'string', 'max:120'], 'customer_name' => ['nullable', 'string', 'max:160'],
            'required_by' => ['nullable', 'date'], 'priority' => ['nullable', 'integer', 'between:1,100'], 'allow_partial' => ['boolean'],
            'lines' => ['required_if:kind,INTERNAL', 'array'], 'lines.*.item' => ['required', 'string'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['nullable', 'integer'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $attrs = array_filter(['required_by' => $data['required_by'] ?? null, 'priority' => $data['priority'] ?? null,
            'allow_partial' => $data['allow_partial'] ?? null], fn ($v) => $v !== null);
        $order = null;

        $response = $this->attempt(function () use ($data, $warehouse, $attrs, $request, &$order) {
            $order = match ($data['kind']) {
                'SALES_ORDER' => $this->orders->fromSalesOrder(FinSalesOrder::query()->with('lines')->findOrFail($data['sales_order_id']), $warehouse, $request->user(), $attrs),
                'MANUFACTURING' => $this->orders->fromProductionOrder(ProductionOrder::query()->findOrFail($data['production_order_id']), $warehouse, $request->user()),
                default => $this->orders->create($warehouse, 'INTERNAL', $data['reference'], array_map(fn ($l) => [
                    'item_id' => FinStockItem::query()->where('uuid', $l['item'])->firstOrFail()->id, 'qty' => $l['qty'], 'uom_id' => $l['uom_id'] ?? null,
                ], $data['lines']), $attrs + ['customer_name' => $data['customer_name'] ?? 'Internal issue'], $request->user())[0],
            };
        }, 'Order taken in.');

        return $order ? redirect()->route('warehouse.outbound.orders.show', $order->uuid)->with('success', "Order {$order->number} taken in.") : $response;
    }

    public function show(Request $request, string $uuid, Packing $packing, \App\Modules\Operations\WarehouseManagement\Services\Carriers $carriers): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->with(['warehouse', 'lines.item:id,uuid,code,name,uom', 'lines.allocations.bin:id,code',
            'lines.allocations.lot:id,lot_number,expires_on', 'packages.contents.item:id,code', 'packages.contents.lot:id,lot_number', 'shipments.packages:id,sscc', 'shipments.trackingEvents', 'shipments.carrierRecord'])->firstOrFail();

        return Inertia::render('Modules/Warehouse/Outbound/OrderShow', [
            'order' => $this->summary($order) + [
                'ship_to' => $order->ship_to, 'notes' => $order->notes, 'cancel_reason' => $order->cancel_reason, 'allow_partial' => $order->allow_partial,
                'allow_substitution' => $order->allow_substitution, 'source_id' => $order->source_id,
                'timeline' => collect(['released_at', 'pick_started_at', 'picked_at', 'packed_at', 'dispatched_at', 'delivered_at'])
                    ->mapWithKeys(fn ($k) => [$k => $order->{$k}?->toIso8601String()]),
            ],
            'lines' => $order->lines->map(fn (FulfilmentLine $l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'item' => $l->item?->code, 'name' => $l->item?->name, 'uom' => $l->item?->uom,
                'qty' => (float) $l->qty, 'allocated' => (float) $l->qty_allocated, 'picked' => (float) $l->qty_picked, 'packed' => (float) $l->qty_packed,
                'dispatched' => (float) $l->qty_dispatched, 'cancelled' => (float) $l->qty_cancelled, 'short' => (float) $l->qty_short,
                'substitute_of' => $l->substitute_of_line_id ? $order->lines->firstWhere('id', $l->substitute_of_line_id)?->line_no : null,
                'allocations' => $l->allocations->whereIn('status', ['ACTIVE', 'PICKED', 'DISPATCHED'])->map(fn ($a) => ['bin' => $a->bin?->code, 'lot' => $a->lot?->lot_number,
                    'expires_on' => $a->lot?->expires_on?->toDateString(), 'qty' => (float) $a->qty, 'picked' => (float) $a->qty_picked, 'status' => $a->status,
                    'override' => $a->override_reason])->values(),
            ]),
            'tasks' => PickTask::query()->where('order_id', $order->id)->with(['bin:id,code', 'item:id,code', 'lot:id,lot_number', 'wave:id,uuid,number,strategy'])->orderBy('id')->get()
                ->map(fn (PickTask $t) => ['id' => $t->uuid, 'wave' => $t->wave?->number, 'wave_id' => $t->wave?->uuid, 'strategy' => $t->wave?->strategy,
                    'bin' => $t->bin?->code, 'item' => $t->item?->code, 'lot' => $t->lot?->lot_number, 'qty' => (float) $t->qty,
                    'picked' => (float) $t->picked_qty, 'short' => (float) $t->short_qty, 'status' => $t->status]),
            'packages' => $order->packages->map(fn (Package $p) => ['id' => $p->uuid, 'sscc' => $p->sscc, 'type' => $p->type, 'status' => $p->status,
                'weight_kg' => $p->weight_kg !== null ? (float) $p->weight_kg : null, 'tracking_number' => $p->tracking_number,
                'contents' => $p->contents->map(fn ($c) => ['id' => $c->id, 'item' => $c->item?->code, 'lot' => $c->lot?->lot_number, 'qty' => (float) $c->qty, 'line_id' => $c->order_line_id])]),
            'shipments' => $order->shipments->map(fn (Shipment $s) => ['id' => $s->uuid, 'number' => $s->number, 'status' => $s->status, 'carrier' => $s->carrier,
                'tracking_reference' => $s->tracking_reference, 'dispatched_at' => $s->dispatched_at?->toIso8601String(), 'handover_name' => $s->handover_name,
                'delivered_at' => $s->delivered_at?->toIso8601String(), 'pod_received_by' => $s->pod_received_by, 'has_pod' => $s->pod_path !== null,
                'delivery_note' => $s->delivery_note_id ? DB::table('fin_delivery_notes')->where('id', $s->delivery_note_id)->value('number') : null,
                'packages' => $s->packages->pluck('sscc'), 'service' => $s->service, 'booking_reference' => $s->booking_reference, 'booked' => $s->booked_at !== null,
                'quoted_price' => $s->quoted_price !== null ? (float) $s->quoted_price : null, 'tracking_status' => $s->tracking_status,
                'tracking_link' => $s->carrierRecord?->trackingLink($s->tracking_reference),
                'tracking' => $s->trackingEvents->map(fn ($t) => ['status' => $t->status, 'occurred_at' => $t->occurred_at?->toIso8601String(), 'location' => $t->location,
                    'detail' => $t->detail, 'source' => $t->source])->values()]),
            'carrier_quote' => in_array($order->status, ['PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED'], true) ? $carriers->quote($order) : null,
            'carriers' => \App\Modules\Operations\WarehouseManagement\Models\Carrier::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name'])
                ->map(fn ($c) => ['value' => $c->uuid, 'title' => $c->name]),
            'tracking_statuses' => \App\Modules\Operations\WarehouseManagement\Models\TrackingEvent::STATUSES,
            'pack_check' => $packing->check($order),
            'strategies' => PickWave::STRATEGIES,
            'package_types' => Package::TYPES,
            'can' => $this->can($request),
        ]);
    }

    public function act(Request $request, string $uuid, Allocator $allocator, Picking $picking): RedirectResponse
    {
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['release', 'allocate', 'reallocate', 'pick', 'cancel'])],
            'strategy' => ['nullable', Rule::in(PickWave::STRATEGIES)], 'reason' => ['nullable', 'string', 'max:1000']]);
        abort_unless($request->user()->can($data['action'] === 'pick' ? RoleCatalog::PICK : RoleCatalog::MANAGE_ORDERS), 403);

        return $this->attempt(fn () => match ($data['action']) {
            'release' => $allocator->allocate($this->orders->release($order)),
            'allocate' => $allocator->allocate($order),
            'reallocate' => $allocator->reallocate($order),
            'pick' => $picking->release($order->warehouse, collect([$order]), $data['strategy'] ?? 'DISCRETE', $request->user()),
            default => $this->orders->cancel($order, (string) ($data['reason'] ?? ''), $request->user()),
        }, 'Order updated.');
    }

    // ── Picking ──────────────────────────────────────────────────────────────────────────────

    public function waves(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Outbound/Waves', [
            'waves' => PickWave::query()->with(['warehouse:id,code'])->withCount(['tasks', 'tasks as open_tasks_count' => fn ($q) => $q->where('status', 'OPEN')])
                ->whereIn('status', $request->input('view') === 'done' ? ['DONE', 'CANCELLED'] : ['OPEN', 'IN_PROGRESS'])->latest('id')->limit(100)->get()
                ->map(fn (PickWave $w) => ['id' => $w->uuid, 'number' => $w->number, 'strategy' => $w->strategy, 'status' => $w->status, 'zone' => $w->zone,
                    'warehouse' => $w->warehouse?->code, 'tasks' => $w->tasks_count, 'open' => $w->open_tasks_count]),
            'ready' => FulfilmentOrder::query()->whereIn('status', ['ALLOCATED', 'PARTIALLY_ALLOCATED'])->with('warehouse:id,uuid,code')
                ->orderByDesc('priority')->orderByRaw('sla_due_at NULLS LAST')->limit(200)->get()
                ->map(fn ($o) => $this->summary($o)),
            'strategies' => PickWave::STRATEGIES,
            'view' => $request->input('view', 'open'),
            'can' => $this->can($request),
        ]);
    }

    public function releaseWave(Request $request, Picking $picking): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PICK), 403);
        $data = $request->validate(['orders' => ['required', 'array', 'min:1'], 'orders.*' => ['string'], 'strategy' => ['required', Rule::in(PickWave::STRATEGIES)]]);
        $orders = FulfilmentOrder::query()->whereIn('uuid', $data['orders'])->get();
        $byWarehouse = $orders->groupBy('warehouse_id');

        return $this->attempt(function () use ($byWarehouse, $picking, $data, $request) {
            foreach ($byWarehouse as $warehouseId => $group) {
                $picking->release(FinWarehouse::query()->findOrFail($warehouseId), $group, $data['strategy'], $request->user());
            }
        }, 'Pick work released.');
    }

    /** The picker's screen: the next task in path order, and the rest of the walk. */
    public function wave(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $wave = PickWave::query()->where('uuid', $uuid)->with(['warehouse', 'tasks.item:id,uuid,code,name,uom', 'tasks.bin:id,code,barcode',
            'tasks.lot:id,lot_number,expires_on', 'tasks.order:id,uuid,number'])->firstOrFail();

        return Inertia::render('Modules/Warehouse/Outbound/Wave', [
            'wave' => ['id' => $wave->uuid, 'number' => $wave->number, 'strategy' => $wave->strategy, 'status' => $wave->status, 'zone' => $wave->zone,
                'warehouse' => $wave->warehouse->code, 'scan_required' => (bool) ($wave->warehouse->policies['scan_to_confirm'] ?? true)],
            'tasks' => $wave->tasks->map(fn (PickTask $t) => ['id' => $t->uuid, 'sequence' => $t->sequence, 'bin' => $t->bin?->code, 'zone' => $t->zone,
                'item' => $t->item?->code, 'name' => $t->item?->name, 'uom' => $t->item?->uom, 'lot' => $t->lot?->lot_number,
                'expires_on' => $t->lot?->expires_on?->toDateString(), 'order' => $t->order?->number, 'order_id' => $t->order?->uuid,
                'slot' => $t->cluster_slot, 'qty' => (float) $t->qty, 'picked' => (float) $t->picked_qty, 'short' => (float) $t->short_qty,
                'status' => $t->status, 'scan_errors' => $t->scan_errors]),
            'can' => $this->can($request),
        ]);
    }

    public function confirmPick(Request $request, string $uuid, Picking $picking): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PICK), 403);
        $task = PickTask::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['qty' => ['required', 'numeric', 'min:0'], 'short' => ['boolean'], 'bin_scan' => ['nullable', 'string', 'max:100'],
            'item_scan' => ['nullable', 'string', 'max:200'], 'lot_scan' => ['nullable', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $picking->confirm($task, (float) $data['qty'], $request->user(), [
            'bin_scan' => $data['bin_scan'] ?? null, 'item_scan' => isset($data['item_scan']) ? str_replace(['[GS]', '<GS>'], "\x1D", $data['item_scan']) : null,
            'lot_scan' => $data['lot_scan'] ?? null,
        ], (bool) ($data['short'] ?? false), $data['note'] ?? null), 'Picked.');
    }

    // ── Packing ──────────────────────────────────────────────────────────────────────────────

    public function pack(Request $request, string $uuid, Packing $packing): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PACK), 403);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['open', 'add', 'remove', 'close', 'reopen'])], 'type' => ['nullable', Rule::in(Package::TYPES)],
            'package' => ['nullable', 'string'], 'line_id' => ['nullable', 'integer'], 'content_id' => ['nullable', 'integer'], 'qty' => ['nullable', 'numeric', 'gt:0'],
            'scan' => ['nullable', 'string', 'max:200'], 'weight_kg' => ['nullable', 'numeric', 'min:0'], 'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'], 'height_cm' => ['nullable', 'numeric', 'min:0'], 'tracking_number' => ['nullable', 'string', 'max:120'],
        ]);
        $package = ! empty($data['package']) ? Package::query()->where('order_id', $order->id)->where('uuid', $data['package'])->firstOrFail() : null;

        return $this->attempt(fn () => match ($data['action']) {
            'open' => $packing->open($order, $data['type'] ?? 'CARTON', $request->user()),
            'add' => $packing->add($package, FulfilmentLine::query()->where('order_id', $order->id)->findOrFail($data['line_id']), (float) $data['qty'], null, $data['scan'] ?? null),
            'remove' => $packing->remove(PackageContent::query()->where('package_id', $package?->id)->findOrFail($data['content_id'])),
            'close' => $packing->close($package, $data),
            default => $packing->reopen($package),
        }, 'Packing updated.');
    }

    /** Packing slip and package labels, printable. */
    public function documents(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->with(['warehouse', 'packages.contents.item:id,code,name,uom', 'packages.contents.lot:id,lot_number'])->firstOrFail();
        $writer = new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd()));

        return Inertia::render('Modules/Warehouse/Outbound/Documents', [
            'order' => $this->summary($order) + ['ship_to' => $order->ship_to],
            'packages' => $order->packages->map(fn (Package $p, $i) => [
                'sscc' => $p->sscc, 'type' => $p->type, 'index' => $i + 1, 'of' => $order->packages->count(), 'weight_kg' => $p->weight_kg !== null ? (float) $p->weight_kg : null,
                'qr' => $writer->writeString('(00)'.$p->sscc), 'tracking_number' => $p->tracking_number,
                'contents' => $p->contents->map(fn ($c) => ['item' => $c->item?->code, 'name' => $c->item?->name, 'uom' => $c->item?->uom, 'lot' => $c->lot?->lot_number, 'qty' => (float) $c->qty]),
            ]),
            'shipment' => $request->filled('shipment') ? Shipment::query()->where('order_id', $order->id)->where('uuid', $request->input('shipment'))->first()?->only(['number', 'carrier', 'tracking_reference', 'vehicle_reg', 'driver_name', 'handover_name']) : null,
        ]);
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────────────────────

    public function dispatch(Request $request, string $uuid, Dispatching $dispatching): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::DISPATCH), 403);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'packages' => ['required', 'array', 'min:1'], 'packages.*' => ['string'], 'carrier' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:80'], 'vehicle_reg' => ['nullable', 'string', 'max:20'], 'driver_name' => ['nullable', 'string', 'max:120'],
            'tracking_reference' => ['nullable', 'string', 'max:120'], 'collection_at' => ['nullable', 'date'], 'handover_name' => ['required', 'string', 'max:160'],
            'signature' => ['nullable', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp'],
            'carrier_id' => ['nullable', 'string'], 'carrier_service' => ['nullable', 'required_with:carrier_id', 'string', 'max:60'],
        ]);
        $packages = Package::query()->where('order_id', $order->id)->whereIn('uuid', $data['packages'])->with('contents')->get();
        $carrier = ! empty($data['carrier_id']) ? \App\Modules\Operations\WarehouseManagement\Models\Carrier::query()->where('uuid', $data['carrier_id'])->firstOrFail() : null;
        unset($data['carrier_id']);

        // Booking with the carrier is part of the dispatch: both happen or neither does.
        return $this->attempt(fn () => DB::transaction(function () use ($dispatching, $order, $packages, $data, $request, $carrier) {
            $shipment = $dispatching->dispatch($order, $packages, $data, $request->user(), $request->file('signature'));
            if ($carrier) {
                app(\App\Modules\Operations\WarehouseManagement\Services\Carriers::class)->book($shipment, $carrier, $data['carrier_service'], $data['tracking_reference'] ?? null, $request->user());
            }
        }), 'Dispatched.');
    }

    /** Record what happened to a shipment after it left (WMS-170). */
    public function tracking(Request $request, string $shipment, \App\Modules\Operations\WarehouseManagement\Services\Carriers $carriers): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::DISPATCH), 403);
        $s = Shipment::query()->where('uuid', $shipment)->firstOrFail();
        $data = $request->validate(['status' => ['required', Rule::in(\App\Modules\Operations\WarehouseManagement\Models\TrackingEvent::STATUSES)],
            'occurred_at' => ['nullable', 'date'], 'location' => ['nullable', 'string', 'max:160'], 'detail' => ['nullable', 'string', 'max:500'],
            'received_by' => ['nullable', 'string', 'max:160']]);

        return $this->attempt(fn () => $carriers->track($s, $data['status'], $data['occurred_at'] ?? now()->toIso8601String(), $data['location'] ?? null,
            $data['detail'] ?? null, 'MANUAL', null, $request->user(), $data['received_by'] ?? null), 'Tracking recorded.');
    }

    public function pod(Request $request, string $shipment, Dispatching $dispatching): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::DISPATCH), 403);
        $s = Shipment::query()->where('uuid', $shipment)->firstOrFail();
        $data = $request->validate(['received_by' => ['required', 'string', 'max:160'], 'delivered_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'], 'pod' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp']]);

        return $this->attempt(fn () => $dispatching->recordDelivery($s, $data['received_by'], $data['delivered_at'] ?? null, $request->file('pod'), $data['note'] ?? null), 'Delivery recorded.');
    }

    public function podFile(string $shipment)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $s = Shipment::query()->where('uuid', $shipment)->whereNotNull('pod_path')->firstOrFail();

        return Storage::disk('documents')->download($s->pod_path);
    }

    // ── Service levels ───────────────────────────────────────────────────────────────────────

    public function sla(Request $request, FulfilmentSla $sla): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->first() : null;

        return Inertia::render('Modules/Warehouse/Outbound/Sla', [
            'metrics' => $sla->metrics($from, $to, $warehouse?->id),
            'at_risk' => FulfilmentOrder::query()->whereIn('status', FulfilmentOrder::LIVE)->whereNotNull('sla_due_at')
                ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
                ->where('sla_due_at', '<=', now()->addHours(4))->orderBy('sla_due_at')->limit(50)->get()->map(fn ($o) => $this->summary($o)),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'warehouse' => $warehouse?->uuid],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    private function summary(FulfilmentOrder $o): array
    {
        $lines = $o->relationLoaded('lines') ? $o->lines : $o->lines()->get();

        return [
            'id' => $o->uuid, 'number' => $o->number, 'source' => $o->source, 'external_ref' => $o->external_ref, 'status' => $o->status,
            'warehouse' => $o->warehouse?->code, 'customer' => $o->customer_name, 'priority' => $o->priority,
            'required_by' => $o->required_by?->toIso8601String(), 'sla_due_at' => $o->sla_due_at?->toIso8601String(),
            'at_risk' => $o->sla_at_risk_at !== null, 'overdue' => $o->sla_due_at !== null && $o->sla_due_at->isPast() && in_array($o->status, FulfilmentOrder::LIVE, true),
            'lines' => $lines->count(), 'qty' => (float) $lines->sum('qty'), 'allocated' => (float) $lines->sum('qty_allocated'),
            'picked' => (float) $lines->sum('qty_picked'), 'dispatched' => (float) $lines->sum('qty_dispatched'), 'short' => (float) $lines->sum('qty_short'),
        ];
    }

    private function can(Request $request): array
    {
        $u = $request->user();

        return ['manage' => $u->can(RoleCatalog::MANAGE_ORDERS), 'pick' => $u->can(RoleCatalog::PICK), 'pack' => $u->can(RoleCatalog::PACK), 'dispatch' => $u->can(RoleCatalog::DISPATCH),
            'quality' => $u->can(RoleCatalog::QUALITY), 'override' => $u->can(RoleCatalog::OVERRIDE_ROTATION)];
    }

    private function warehouseOptions(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get(['uuid', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"])->all();
    }
}
