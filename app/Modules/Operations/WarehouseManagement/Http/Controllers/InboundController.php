<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\DockAppointment;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Models\ReceiptAttachment;
use App\Modules\Operations\WarehouseManagement\Models\ReceiptDiscrepancy;
use App\Modules\Operations\WarehouseManagement\Services\ReceiptDiscrepancies;
use App\Modules\Operations\WarehouseManagement\Services\Receiving;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** WMS-030 to WMS-033: expected receipts, receiving, receipts without an order, discrepancies. */
class InboundController extends Controller
{
    use RefusesPolitely;

    public function __construct(private readonly Receiving $receiving) {}

    public function expected(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;

        return Inertia::render('Modules/Warehouse/Inbound/Expected', [
            'lines' => $this->receiving->expected($warehouse?->id, $request->boolean('overdue')),
            'filters' => ['warehouse' => $warehouse?->uuid, 'overdue' => $request->boolean('overdue')],
            'warehouses' => $this->warehouses(),
            'units' => $this->units($request),
            'can' => ['receive' => $request->user()->can(RoleCatalog::RECEIVE), 'link' => $request->user()->can(RoleCatalog::MANAGE_ITEMS)],
        ]);
    }

    public function linkLine(Request $request, string $line): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_ITEMS), 403);
        $data = $request->validate(['item' => ['required', 'string'], 'uom_id' => ['nullable', 'integer']]);
        $poLine = PurchaseOrderItem::query()->where('uuid', $line)->firstOrFail();
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();

        return $this->attempt(fn () => $this->receiving->linkLine($poLine, $item, $data['uom_id'] ?? null), 'Order line linked to the stock item.');
    }

    /** The receiving screen for one purchase order. */
    public function receiveForm(Request $request, string $po): Response
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $order = PurchaseOrder::query()->where('uuid', $po)->with('items')->firstOrFail();
        $stock = FinStockItem::query()->whereIn('id', $order->items->pluck('stock_item_id')->filter())->get()->keyBy('id');
        $profiles = DB::table('mfg_items')->whereIn('stock_item_id', $stock->keys())->get(['stock_item_id', 'is_lot_tracked', 'is_serial_tracked'])->keyBy('stock_item_id');
        $warehouseId = $order->warehouse_id ?? FinWarehouse::query()->where('is_default', true)->value('id');

        return Inertia::render('Modules/Warehouse/Inbound/Receive', [
            'order' => ['id' => $order->uuid, 'number' => $order->po_number, 'supplier' => DB::table('suppliers')->where('id', $order->supplier_id)->value('company_name'),
                'expected_delivery' => $order->expected_delivery, 'status' => $order->status,
                'warehouse' => $warehouseId ? FinWarehouse::query()->find($warehouseId)?->uuid : null],
            'lines' => $order->items->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'ordered' => (float) $i->quantity, 'received' => (float) $i->received_qty,
                'outstanding' => round((float) $i->quantity - (float) $i->received_qty, 4),
                'stock_item' => $i->stock_item_id ? ['id' => $stock[$i->stock_item_id]?->uuid, 'code' => $stock[$i->stock_item_id]?->code] : null,
                'uom' => $i->uom_id ? Uom::query()->find($i->uom_id)?->code : ($i->stock_item_id ? $stock[$i->stock_item_id]?->uom : null),
                'lot_tracked' => (bool) ($profiles[$i->stock_item_id]->is_lot_tracked ?? false),
                'serial_tracked' => (bool) ($profiles[$i->stock_item_id]->is_serial_tracked ?? false),
            ])->values(),
            'warehouses' => $this->warehouses(),
            'appointments' => DockAppointment::query()->where('purchase_order_id', $order->id)->whereIn('status', ['ARRIVED', 'UNLOADING'])
                ->get(['id', 'number'])->map(fn ($a) => ['id' => $a->id, 'title' => $a->number]),
            'can' => ['exceptions' => $request->user()->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS)],
        ]);
    }

    public function receive(Request $request, string $po): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $order = PurchaseOrder::query()->where('uuid', $po)->firstOrFail();
        $data = $request->validate([
            'warehouse' => ['required', 'string'],
            'delivery_note_ref' => ['nullable', 'string', 'max:80'], 'vehicle_reg' => ['nullable', 'string', 'max:20'],
            'appointment_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000'],
            'allow_duplicate' => ['boolean'], 'idempotency_key' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.accepted_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.rejected_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.damaged_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.missing_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:64'],
            'lines.*.expires_on' => ['nullable', 'date'], 'lines.*.manufactured_on' => ['nullable', 'date'],
            'lines.*.serial_numbers' => ['nullable', 'array'], 'lines.*.serial_numbers.*' => ['string', 'max:64'],
            'lines.*.keep_rejected' => ['boolean'], 'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $lines = array_values(array_filter($data['lines'], fn ($l) => (float) $l['accepted_qty'] > 0 || (float) ($l['rejected_qty'] ?? 0) > 0));
        foreach ($lines as &$l) {
            if ((float) ($l['damaged_qty'] ?? 0) > 0) {
                $l['condition'] = 'damaged';
            }
        }
        $grn = null;

        $response = $this->attempt(function () use ($order, $lines, $data, $warehouse, $request, &$grn) {
            $grn = $this->receiving->receiveOrder($order, $lines, [
                'warehouse_id' => $warehouse->id, 'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
                'vehicle_reg' => $data['vehicle_reg'] ?? null, 'appointment_id' => $data['appointment_id'] ?? null,
                'notes' => $data['notes'] ?? null, 'allow_duplicate' => (bool) ($data['allow_duplicate'] ?? false),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ], $request->user());
        }, 'Goods received.');

        return $grn ? redirect()->route('warehouse.inbound.receipts.show', $grn->uuid)->with('success', "Received on {$grn->grn_number}.") : $response;
    }

    public function nonPoForm(Request $request): Response
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $cid = app(CurrentCompany::class)->id() ?? $request->user()?->company_id;

        return Inertia::render('Modules/Warehouse/Inbound/ReceiveWithoutOrder', [
            'warehouses' => $this->warehouses(),
            'suppliers' => DB::table('suppliers')->whereNull('deleted_at')->when($cid, fn ($q) => $q->where('company_id', $cid))
                ->orderBy('company_name')->limit(1000)->get(['id', 'company_name as name']),
            'units' => $this->units($request),
        ]);
    }

    public function receiveWithoutOrder(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'supplier_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:1000'], 'delivery_note_ref' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item' => ['required', 'string'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'], 'lines.*.uom_id' => ['nullable', 'integer'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'], 'lines.*.lot_number' => ['nullable', 'string', 'max:64'],
            'lines.*.expires_on' => ['nullable', 'date'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $lines = array_map(fn ($l) => ['stock_item_id' => FinStockItem::query()->where('uuid', $l['item'])->firstOrFail()->id] + $l, $data['lines']);
        $grn = null;

        $response = $this->attempt(function () use ($warehouse, $data, $lines, $request, &$grn) {
            $grn = $this->receiving->receiveWithoutOrder($warehouse, $data['supplier_id'] ?? null, $lines, $data['reason'], $request->user(),
                ['delivery_note_ref' => $data['delivery_note_ref'] ?? null]);
        }, 'Receipt recorded; it posts once approved.');

        return $grn ? redirect()->route('warehouse.inbound.receipts.show', $grn->uuid)->with('success', "Recorded on {$grn->grn_number}; waiting for approval.") : $response;
    }

    public function receipts(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $list = GoodsReceivedNote::query()->with(['purchaseOrder:id,uuid,po_number,supplier_id'])
            ->when($request->filled('discrepancy'), fn ($q) => $q->where('discrepancy_status', 'OPEN'))
            ->when($request->filled('pending'), fn ($q) => $q->where('approval_status', 'PENDING'))
            ->latest('id')->paginate(30)->withQueryString();
        $suppliers = DB::table('suppliers')->whereIn('id', collect($list->items())->map(fn ($g) => $g->purchaseOrder?->supplier_id ?? $g->supplier_id)->filter())->pluck('company_name', 'id');

        return Inertia::render('Modules/Warehouse/Inbound/Receipts', [
            'receipts' => $list->through(fn (GoodsReceivedNote $g) => [
                'id' => $g->uuid, 'number' => $g->grn_number, 'po' => $g->purchaseOrder?->po_number, 'source' => $g->source,
                'supplier' => $suppliers[$g->purchaseOrder?->supplier_id ?? $g->supplier_id] ?? null,
                'received_at' => $g->received_at?->toIso8601String(), 'delivery_note_ref' => $g->delivery_note_ref,
                'approval_status' => $g->approval_status, 'discrepancy_status' => $g->discrepancy_status,
                'inspection_status' => $g->inspection_status, 'posted' => $g->posted_at !== null, 'value' => (float) $g->received_value,
            ]),
            'filters' => $request->only(['discrepancy', 'pending']),
            'counts' => [
                'open_discrepancies' => GoodsReceivedNote::query()->where('discrepancy_status', 'OPEN')->count(),
                'pending_approval' => GoodsReceivedNote::query()->where('approval_status', 'PENDING')->count(),
            ],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $grn = GoodsReceivedNote::query()->where('uuid', $uuid)->with(['items', 'purchaseOrder'])->firstOrFail();
        $items = FinStockItem::query()->whereIn('id', $grn->items->pluck('stock_item_id')->filter())->get()->keyBy('id');
        $user = $request->user();

        return Inertia::render('Modules/Warehouse/Inbound/ReceiptShow', [
            'receipt' => [
                'id' => $grn->uuid, 'number' => $grn->grn_number, 'source' => $grn->source, 'po' => $grn->purchaseOrder?->po_number,
                'po_id' => $grn->purchaseOrder?->uuid,
                'supplier' => DB::table('suppliers')->where('id', $grn->purchaseOrder?->supplier_id ?? $grn->supplier_id)->value('company_name'),
                'supplier_id' => $grn->purchaseOrder?->supplier_id ?? $grn->supplier_id,
                'warehouse' => $grn->warehouse_id ? FinWarehouse::query()->find($grn->warehouse_id)?->code : null,
                'received_at' => $grn->received_at?->toIso8601String(), 'received_by' => DB::table('users')->where('id', $grn->received_by)->value('email'),
                'delivery_note_ref' => $grn->delivery_note_ref, 'vehicle_reg' => $grn->vehicle_reg, 'notes' => $grn->notes,
                'non_po_reason' => $grn->non_po_reason, 'approval_status' => $grn->approval_status,
                'discrepancy_status' => $grn->discrepancy_status, 'inspection_status' => $grn->inspection_status,
                'posted_at' => $grn->posted_at?->toIso8601String(), 'value' => (float) $grn->received_value,
                'mine' => (int) $grn->received_by === $user->id,
            ],
            'lines' => $grn->items->map(fn ($l) => [
                'id' => $l->id, 'name' => $l->description ?? $l->purchaseOrderItem?->name, 'item' => $items[$l->stock_item_id]?->code ?? null,
                'item_id' => $items[$l->stock_item_id]?->uuid ?? null, 'ordered' => $l->ordered_qty !== null ? (float) $l->ordered_qty : null,
                'received' => (float) $l->received_qty, 'accepted' => (float) $l->accepted_qty, 'rejected' => (float) $l->rejected_qty,
                'damaged' => (float) $l->damaged_qty, 'missing' => (float) $l->missing_qty, 'base_qty' => $l->base_qty !== null ? (float) $l->base_qty : null,
                'uom' => $items[$l->stock_item_id]?->uom ?? null, 'stock_status' => $l->stock_status,
                'lot' => $l->lot_id ? DB::table('fin_stock_lots')->where('id', $l->lot_id)->value('lot_number') : null,
                'expires_on' => $l->expires_on?->toDateString(), 'serials' => $l->serial_numbers ?? [],
                'bin' => $l->bin_id ? FinStockBin::query()->find($l->bin_id)?->code : null, 'posted' => $l->movement_id !== null,
            ]),
            'discrepancies' => ReceiptDiscrepancy::query()->where('grn_id', $grn->id)->orderBy('id')->get()
                ->map(fn ($d) => $d->only(['uuid', 'kind', 'expected', 'actual', 'within_tolerance', 'detail', 'status', 'resolution']) + [
                    'resolved_by' => $d->resolved_by ? DB::table('users')->where('id', $d->resolved_by)->value('email') : null]),
            'attachments' => ReceiptAttachment::query()->where('grn_id', $grn->id)->get()
                ->map(fn ($a) => $a->only(['uuid', 'kind', 'original_name', 'mime', 'size'])),
            'putaway' => PutawayTask::query()->where('source_type', 'GRN')->where('source_id', $grn->id)->with(['suggestedBin:id,code', 'toBin:id,code', 'item:id,code'])->get()
                ->map(fn ($t) => ['id' => $t->uuid, 'number' => $t->number, 'item' => $t->item?->code, 'qty' => (float) $t->qty,
                    'status' => $t->status, 'suggested' => $t->suggestedBin?->code, 'to' => $t->toBin?->code]),
            'can' => ['exceptions' => $user->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS), 'receive' => $user->can(RoleCatalog::RECEIVE),
                'returns' => $user->can(RoleCatalog::SUPPLIER_RETURNS)],
        ]);
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS), 403);
        $grn = GoodsReceivedNote::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->receiving->decideWithoutOrder($grn, $request->user(), $data['decision'] === 'approve', $data['note'] ?? null),
            $data['decision'] === 'approve' ? 'Approved and posted.' : 'Receipt rejected.');
    }

    public function resolve(Request $request, string $discrepancy, ReceiptDiscrepancies $service): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS), 403);
        $d = ReceiptDiscrepancy::query()->where('uuid', $discrepancy)->firstOrFail();
        $data = $request->validate(['decision' => ['required', Rule::in(['RESOLVE', 'REJECT'])], 'resolution' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $service->resolve($d, $request->user(), $data['decision'], $data['resolution']), 'Discrepancy settled.');
    }

    public function attach(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECEIVE), 403);
        $grn = GoodsReceivedNote::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,heic,tif,tiff'],
            'kind' => ['required', Rule::in(['DOCUMENT', 'PHOTO'])],
        ]);

        return $this->attempt(fn () => $this->receiving->attach($grn, $request->file('file'), $data['kind'], $request->user()), 'Attached.');
    }

    public function download(string $uuid, string $attachment): StreamedResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $grn = GoodsReceivedNote::query()->where('uuid', $uuid)->firstOrFail();
        $a = ReceiptAttachment::query()->where('grn_id', $grn->id)->where('uuid', $attachment)->firstOrFail();

        return Storage::disk('documents')->download($a->path, $a->original_name);
    }

    private function warehouses(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get(['uuid', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"])->all();
    }

    private function units(Request $request): array
    {
        $cid = app(CurrentCompany::class)->id() ?? $request->user()?->company_id;

        return Uom::query()->when($cid, fn ($q) => $q->where('company_id', $cid))->where('is_active', true)->orderBy('code')
            ->get(['id', 'code', 'name'])->map(fn ($u) => ['id' => $u->id, 'title' => "{$u->code} — {$u->name}"])->all();
    }
}
