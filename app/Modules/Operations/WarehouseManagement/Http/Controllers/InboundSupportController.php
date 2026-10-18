<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\HR\Support\PicksTenantPeople;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\DockAppointment;
use App\Modules\Operations\WarehouseManagement\Models\SupplierReturn;
use App\Modules\Operations\WarehouseManagement\Services\DockAppointments;
use App\Modules\Operations\WarehouseManagement\Services\SupplierReturns;
use App\Modules\Operations\WarehouseManagement\Services\SupplierScorecard;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-031 dock appointments, WMS-034 supplier performance, WMS-035 supplier returns. */
class InboundSupportController extends Controller
{
    use PicksTenantPeople, RefusesPolitely;

    // ── Appointments ─────────────────────────────────────────────────────────────────────────

    public function appointments(Request $request, DockAppointments $service): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $request->filled('warehouse')
            ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail()
            : FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->first();
        $day = Carbon::parse($request->input('day', now()->toDateString()));
        $cid = $this->cid($request);

        return Inertia::render('Modules/Warehouse/Inbound/Appointments', [
            'warehouses' => $this->warehouseOptions(),
            'warehouse' => $warehouse?->uuid,
            'day' => $day->toDateString(),
            'queue' => $warehouse ? $service->queue($warehouse, $day) : [],
            'docks' => $warehouse ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('kind', 'RECEIVING')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code'])->map(fn ($b) => ['id' => $b->id, 'title' => $b->code]) : [],
            'orders' => PurchaseOrder::query()->whereIn('status', \App\Modules\Operations\WarehouseManagement\Services\Receiving::RECEIVABLE)
                ->where(fn ($q) => $q->whereNull('received_status')->orWhere('received_status', '!=', 'received'))
                ->orderByDesc('id')->limit(300)->get(['id', 'po_number', 'supplier_id'])->map(fn ($o) => ['id' => $o->id, 'title' => $o->po_number, 'supplier_id' => $o->supplier_id]),
            'suppliers' => DB::table('suppliers')->whereNull('deleted_at')->when($cid, fn ($q) => $q->where('company_id', $cid))->orderBy('company_name')->limit(1000)->get(['id', 'company_name as title']),
            'people' => $this->tenantPeopleOptions($request),
            'can' => ['book' => $request->user()->can(RoleCatalog::RECEIVE)],
        ]);
    }

    public function book(Request $request, DockAppointments $service): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'dock_bin_id' => ['nullable', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'],
            'slot_start' => ['required', 'date'], 'slot_end' => ['required', 'date', 'after:slot_start'],
            'carrier' => ['nullable', 'string', 'max:120'], 'vehicle_reg' => ['nullable', 'string', 'max:20'],
            'driver_name' => ['nullable', 'string', 'max:120'], 'driver_phone' => ['nullable', 'string', 'max:40'],
            'assigned_user_id' => ['nullable', 'integer', Rule::in($this->tenantPeopleIds($request))], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        if (! empty($data['purchase_order_id'])) {
            $data['supplier_id'] ??= PurchaseOrder::query()->findOrFail($data['purchase_order_id'])->supplier_id;
        }

        return $this->attempt(fn () => $service->book($warehouse, $data, $request->user()), 'Appointment booked.');
    }

    public function moveAppointment(Request $request, string $uuid, DockAppointments $service): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $a = DockAppointment::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['to' => ['required', Rule::in(DockAppointment::STATUSES)], 'vehicle_reg' => ['nullable', 'string', 'max:20'],
            'driver_name' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $service->move($a, $data['to'], $request->user(), $data), 'Appointment updated.');
    }

    // ── Supplier performance ─────────────────────────────────────────────────────────────────

    public function scorecards(Request $request, SupplierScorecard $scorecard): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $from = Carbon::parse($request->input('from', now()->subDays(90)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        return Inertia::render('Modules/Warehouse/Inbound/Scorecards', [
            'scores' => $scorecard->scores($from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'thresholds' => ['on_time' => SupplierScorecard::ON_TIME_FLOOR, 'defects' => SupplierScorecard::DEFECT_CEILING, 'min' => SupplierScorecard::MIN_DELIVERIES],
            'flags' => DB::table('supplier_risk_flags')->where('type', 'delivery_performance')->where('status', 'open')->whereNull('deleted_at')
                ->pluck('message', 'supplier_id'),
        ]);
    }

    // ── Supplier returns ─────────────────────────────────────────────────────────────────────

    public function returns(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $cid = $this->cid($request);
        $list = SupplierReturn::query()->with(['lines.item:id,code,name,uom', 'lines.bin:id,code', 'lines.lot:id,lot_number', 'warehouse:id,code', 'grn:id,grn_number'])
            ->latest('id')->paginate(30)->withQueryString();
        $suppliers = DB::table('suppliers')->whereIn('id', collect($list->items())->pluck('supplier_id'))->pluck('company_name', 'id');

        $prefillGrn = $request->filled('grn') ? GoodsReceivedNote::query()->where('uuid', $request->input('grn'))->with('purchaseOrder')->first() : null;

        return Inertia::render('Modules/Warehouse/Inbound/Returns', [
            'returns' => $list->through(fn (SupplierReturn $r) => [
                'id' => $r->uuid, 'number' => $r->number, 'status' => $r->status, 'supplier' => $suppliers[$r->supplier_id] ?? null,
                'warehouse' => $r->warehouse?->code, 'grn' => $r->grn?->grn_number, 'reason' => $r->reason, 'carrier' => $r->carrier,
                'dispatch_reference' => $r->dispatch_reference, 'dispatched_at' => $r->dispatched_at?->toIso8601String(),
                'acknowledged_by_name' => $r->acknowledged_by_name, 'outcome' => $r->outcome, 'credit_reference' => $r->credit_reference,
                'lines' => $r->lines->map(fn ($l) => ['item' => $l->item?->code, 'name' => $l->item?->name, 'qty' => (float) $l->qty, 'uom' => $l->item?->uom,
                    'bin' => $l->bin?->code, 'lot' => $l->lot?->lot_number, 'status' => $l->stock_status]),
            ]),
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => DB::table('suppliers')->whereNull('deleted_at')->when($cid, fn ($q) => $q->where('company_id', $cid))->orderBy('company_name')->limit(1000)->get(['id', 'company_name as title']),
            'prefill' => $prefillGrn ? ['grn' => $prefillGrn->uuid, 'grn_number' => $prefillGrn->grn_number,
                'supplier_id' => $prefillGrn->purchaseOrder?->supplier_id ?? $prefillGrn->supplier_id,
                'warehouse' => $prefillGrn->warehouse_id ? FinWarehouse::query()->find($prefillGrn->warehouse_id)?->uuid : null] : null,
            'can' => ['manage' => $request->user()->can(RoleCatalog::SUPPLIER_RETURNS)],
        ]);
    }

    /** Held stock that could go back: rejected, quarantined, damaged or recalled positions. */
    public function returnable(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail();

        return response()->json(FinStockPosition::query()->where('warehouse_id', $warehouse->id)->where('qty', '>', 0)
            ->whereIn('status', [FinStockPosition::REJECTED, FinStockPosition::QUARANTINE, FinStockPosition::DAMAGED, FinStockPosition::RECALLED, FinStockPosition::AVAILABLE])
            ->with(['item:id,uuid,code,name,uom', 'bin:id,code', 'lot:id,lot_number'])->orderBy('status')->limit(500)->get()
            ->map(fn ($p) => ['item_id' => $p->item_id, 'item' => $p->item?->code, 'name' => $p->item?->name, 'uom' => $p->item?->uom,
                'bin_id' => $p->bin_id, 'bin' => $p->bin?->code, 'lot_id' => $p->lot_id, 'lot' => $p->lot?->lot_number,
                'status' => $p->status, 'qty' => (float) $p->qty]));
    }

    public function storeReturn(Request $request, SupplierReturns $service): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPPLIER_RETURNS), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->when($this->cid($request), fn ($r, $c) => $r->where('company_id', $c))],
            'grn' => ['nullable', 'string'], 'reason' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'], 'lines.*.bin_id' => ['nullable', 'integer'],
            'lines.*.lot_id' => ['nullable', 'integer'], 'lines.*.stock_status' => ['required', Rule::in(FinStockPosition::STATUSES)],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        foreach ($data['lines'] as $l) {
            FinStockItem::query()->findOrFail($l['item_id']);
            if (! empty($l['bin_id'])) {
                FinStockBin::query()->where('warehouse_id', $warehouse->id)->findOrFail($l['bin_id']);
            }
            if (! empty($l['lot_id'])) {
                FinStockLot::query()->findOrFail($l['lot_id']);
            }
        }
        $grnId = ! empty($data['grn']) ? GoodsReceivedNote::query()->where('uuid', $data['grn'])->value('id') : null;

        return $this->attempt(fn () => $service->draft($warehouse, (int) $data['supplier_id'], $data['reason'], $data['lines'], $request->user(), $grnId), 'Return drafted.');
    }

    public function moveReturn(Request $request, string $uuid, SupplierReturns $service): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPPLIER_RETURNS), 403);
        $r = SupplierReturn::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'dispatch', 'acknowledge', 'close', 'cancel'])],
            'carrier' => ['nullable', 'string', 'max:120'], 'reference' => ['nullable', 'string', 'max:120'],
            'acknowledged_by' => ['nullable', 'string', 'max:160'], 'outcome' => ['nullable', Rule::in(['REPLACEMENT', 'CREDIT', 'NONE'])],
            'credit_reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->attempt(fn () => match ($data['action']) {
            'approve' => $service->approve($r, $request->user()),
            'dispatch' => $service->dispatch($r, $request->user(), $data['carrier'] ?? null, $data['reference'] ?? null),
            'acknowledge' => $service->acknowledge($r, (string) ($data['acknowledged_by'] ?? '')),
            'close' => $service->close($r, (string) ($data['outcome'] ?? ''), $data['credit_reference'] ?? null),
            default => $service->cancel($r),
        }, 'Return updated.');
    }

    private function warehouseOptions(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get(['uuid', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"])->all();
    }

    private function cid(Request $request): ?int
    {
        return app(CurrentCompany::class)->id() ?? $request->user()?->company_id;
    }
}
