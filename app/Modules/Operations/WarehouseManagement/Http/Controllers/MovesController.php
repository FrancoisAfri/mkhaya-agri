<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\ReplenishmentRule;
use App\Modules\Operations\WarehouseManagement\Models\ReplenishmentTask;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use App\Modules\Operations\WarehouseManagement\Services\BinTransfers;
use App\Modules\Operations\WarehouseManagement\Services\Replenishment;
use App\Modules\Operations\WarehouseManagement\Services\TransferOrders;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-050 bin moves, WMS-051 transfer orders, WMS-052/053 replenishment. */
class MovesController extends Controller
{
    use RefusesPolitely;

    // ── Bin moves ────────────────────────────────────────────────────────────────────────────

    public function binMove(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $this->warehouseOrDefault($request);
        // A scanner sends the bin's barcode, a person types its code, a link carries its uuid --
        // and only the last may be compared with the uuid column, which refuses anything else.
        $key = trim((string) $request->input('bin', ''));
        $from = $warehouse && $key !== ''
            ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where(fn ($q) => $q
                ->when(\Illuminate\Support\Str::isUuid($key), fn ($w) => $w->where('uuid', $key))
                ->orWhere('barcode', $key)->orWhere('code', strtoupper($key)))->first()
            : null;

        return Inertia::render('Modules/Warehouse/Moves/BinMove', [
            'warehouses' => $this->warehouseOptions(),
            'warehouse' => $warehouse?->uuid,
            'from' => $from ? ['id' => $from->uuid, 'code' => $from->code] : null,
            'contents' => $from ? FinStockPosition::query()->where('bin_id', $from->id)->where('qty', '>', 0)
                ->with(['item:id,uuid,code,name,uom', 'lot:id,lot_number,expires_on'])->get()
                ->map(fn ($p) => ['item' => $p->item?->uuid, 'code' => $p->item?->code, 'name' => $p->item?->name, 'uom' => $p->item?->uom,
                    'lot_id' => $p->lot_id, 'lot' => $p->lot?->lot_number, 'status' => $p->status, 'qty' => (float) $p->qty]) : [],
            'bins' => $warehouse ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('level', 'BIN')->where('is_active', true)
                ->where('status', FinStockBin::OPEN)->orderBy('code')->get(['uuid', 'code', 'kind'])->map(fn ($b) => ['id' => $b->uuid, 'title' => "{$b->code} ({$b->kind})"]) : [],
            'scan_required' => (bool) ($warehouse?->policies['scan_to_confirm'] ?? true),
            'can' => ['move' => $request->user()->can(RoleCatalog::MOVE_STOCK)],
        ]);
    }

    public function doBinMove(Request $request, BinTransfers $moves): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'from' => ['nullable', 'string'], 'to' => ['required', 'string'],
            'item' => ['required', 'string'], 'lot_id' => ['nullable', 'integer'], 'status' => ['required', Rule::in(FinStockPosition::STATUSES)],
            'qty' => ['required', 'numeric', 'gt:0'], 'item_scan' => ['nullable', 'string', 'max:200'], 'to_scan' => ['nullable', 'string', 'max:100'],
            'from_scan' => ['nullable', 'string', 'max:100'], 'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $bin = fn (?string $u) => $u ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('uuid', $u)->firstOrFail() : null;

        return $this->attempt(fn () => $moves->move($warehouse, FinStockItem::query()->where('uuid', $data['item'])->firstOrFail(),
            $bin($data['from'] ?? null), $bin($data['to']), (float) $data['qty'], $data['lot_id'] ?? null, $data['status'], $request->user(),
            ['item_scan' => $data['item_scan'] ?? null, 'to_scan' => $data['to_scan'] ?? null, 'from_scan' => $data['from_scan'] ?? null],
            $data['reason'] ?? null), 'Moved.');
    }

    // ── Transfer orders ──────────────────────────────────────────────────────────────────────

    public function transfers(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $list = TransferOrder::query()->with(['from:id,code', 'to:id,code', 'lines.item:id,code,name,uom', 'lines.lot:id,lot_number', 'lines.fromBin:id,code'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Modules/Warehouse/Moves/Transfers', [
            'transfers' => $list->through(fn (TransferOrder $o) => [
                'id' => $o->uuid, 'number' => $o->number, 'status' => $o->status, 'from' => $o->from?->code, 'to' => $o->to?->code,
                'reason' => $o->reason, 'priority' => $o->priority, 'source' => $o->source, 'needed_by' => $o->needed_by?->toDateString(),
                'carrier' => $o->carrier, 'tracking_reference' => $o->tracking_reference, 'cancel_reason' => $o->cancel_reason,
                'dispatched_at' => $o->dispatched_at?->toIso8601String(), 'received_at' => $o->received_at?->toIso8601String(),
                'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'item' => $l->item?->code, 'name' => $l->item?->name, 'uom' => $l->item?->uom,
                    'lot' => $l->lot?->lot_number, 'from_bin' => $l->fromBin?->code, 'qty' => (float) $l->qty, 'picked' => (float) $l->picked_qty,
                    'dispatched' => (float) $l->dispatched_qty, 'received' => (float) $l->received_qty, 'variance' => (float) $l->variance_qty]),
            ]),
            'filters' => ['status' => $request->input('status')],
            'statuses' => TransferOrder::STATUSES,
            'warehouses' => $this->warehouseOptions(),
            'can' => ['move' => $request->user()->can(RoleCatalog::MOVE_STOCK), 'approve' => $request->user()->can(RoleCatalog::APPROVE_TRANSFERS)],
        ]);
    }

    public function storeTransfer(Request $request, TransferOrders $transfers): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $data = $request->validate([
            'from' => ['required', 'string'], 'to' => ['required', 'string', 'different:from'], 'reason' => ['nullable', 'string', 'max:1000'],
            'needed_by' => ['nullable', 'date'], 'priority' => ['nullable', 'integer', 'between:1,100'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item' => ['required', 'string'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.from_bin' => ['nullable', 'string'], 'lines.*.lot_id' => ['nullable', 'integer'],
        ]);
        $from = FinWarehouse::query()->where('uuid', $data['from'])->firstOrFail();
        $to = FinWarehouse::query()->where('uuid', $data['to'])->firstOrFail();
        $lines = array_map(fn ($l) => [
            'item_id' => FinStockItem::query()->where('uuid', $l['item'])->firstOrFail()->id, 'qty' => $l['qty'], 'lot_id' => $l['lot_id'] ?? null,
            'from_bin_id' => ! empty($l['from_bin']) ? FinStockBin::query()->where('warehouse_id', $from->id)->where('uuid', $l['from_bin'])->firstOrFail()->id : null,
        ], $data['lines']);

        return $this->attempt(fn () => $transfers->draft($from, $to, $lines, $request->user(), $data), 'Transfer drafted.');
    }

    public function moveTransfer(Request $request, string $uuid, TransferOrders $transfers): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $order = TransferOrder::query()->where('uuid', $uuid)->with('lines')->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'pick', 'dispatch', 'receive', 'reconcile', 'cancel'])],
            'lines' => ['nullable', 'array'], 'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'lines.*.to_bin' => ['nullable', 'string'],
            'carrier' => ['nullable', 'string', 'max:120'], 'vehicle_reg' => ['nullable', 'string', 'max:20'], 'tracking_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $lines = $data['lines'] ?? [];
        $user = $request->user();

        return $this->attempt(fn () => match ($data['action']) {
            'approve' => $transfers->approve($order, $user),
            'pick' => $transfers->pick($order, array_map(fn ($l) => (float) ($l['qty'] ?? 0), $lines), $user),
            'dispatch' => $transfers->dispatch($order, $user, $data),
            'receive' => $transfers->receive($order, array_map(fn ($l) => ['qty' => (float) ($l['qty'] ?? 0),
                'to_bin_id' => ! empty($l['to_bin']) ? FinStockBin::query()->where('warehouse_id', $order->to_warehouse_id)->where('uuid', $l['to_bin'])->firstOrFail()->id : null], $lines), $user),
            'reconcile' => $transfers->reconcile($order, $user, $data['note'] ?? null),
            default => $transfers->cancel($order, $user, (string) ($data['note'] ?? '')),
        }, 'Transfer updated.');
    }

    // ── Replenishment ────────────────────────────────────────────────────────────────────────

    public function replenishment(Request $request, Replenishment $replenishment): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Moves/Replenishment', [
            'tasks' => ReplenishmentTask::query()->with(['item:id,uuid,code,name,uom', 'warehouse:id,code', 'fromBin:id,code', 'toBin:id,code'])
                ->whereIn('status', $request->input('view') === 'done' ? ['DONE', 'CANCELLED'] : ['OPEN', 'PARTIAL', 'FAILED'])
                ->orderByDesc('priority')->orderBy('id')->limit(200)->get()
                ->map(fn (ReplenishmentTask $t) => ['id' => $t->uuid, 'number' => $t->number, 'status' => $t->status, 'priority' => $t->priority,
                    'reasons' => $t->priority_reasons ?? [], 'warehouse' => $t->warehouse?->code, 'item' => $t->item?->code, 'name' => $t->item?->name,
                    'uom' => $t->item?->uom, 'from' => $t->fromBin?->code, 'to' => $t->toBin?->code, 'qty' => (float) $t->qty, 'done' => (float) $t->done_qty,
                    'transfer' => $t->transfer_id ? TransferOrder::query()->find($t->transfer_id)?->number : null, 'exception' => $t->exception_note]),
            'rules' => ReplenishmentRule::query()->with(['item:id,uuid,code,name', 'warehouse:id,uuid,code', 'pickBin:id,uuid,code', 'sourceWarehouse:id,code'])->get()
                ->map(fn (ReplenishmentRule $r) => ['id' => $r->uuid, 'item' => $r->item?->code, 'item_id' => $r->item?->uuid, 'warehouse' => $r->warehouse?->code,
                    'warehouse_id' => $r->warehouse?->uuid, 'pick_bin' => $r->pickBin?->code, 'pick_bin_id' => $r->pickBin?->uuid, 'source' => $r->source,
                    'source_warehouse' => $r->sourceWarehouse?->code, 'min_qty' => (float) $r->min_qty, 'max_qty' => (float) $r->max_qty,
                    'multiple' => $r->multiple !== null ? (float) $r->multiple : null, 'is_active' => $r->is_active]),
            'suggestions' => $replenishment->reorderSuggestions(),
            'warehouses' => $this->warehouseOptions(),
            'view' => $request->input('view', 'open'),
            'can' => ['rules' => $request->user()->can(RoleCatalog::MANAGE_REPLENISHMENT), 'move' => $request->user()->can(RoleCatalog::MOVE_STOCK),
                'request' => $request->user()->can(RoleCatalog::MANAGE_REPLENISHMENT)],
        ]);
    }

    /** Bins of one warehouse, for the rule form's pick-face choice. */
    public function bins(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail();

        return response()->json(FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('level', 'BIN')->where('is_active', true)
            ->orderBy('code')->get(['uuid', 'code', 'kind'])->map(fn ($b) => ['id' => $b->uuid, 'title' => "{$b->code} ({$b->kind})"]));
    }

    public function saveRule(Request $request, Replenishment $replenishment, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_REPLENISHMENT), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'item' => ['required', 'string'], 'source' => ['required', Rule::in(['BULK', 'WAREHOUSE'])],
            'pick_bin' => ['nullable', 'string'], 'source_warehouse' => ['nullable', 'string'],
            'min_qty' => ['required', 'numeric', 'min:0'], 'max_qty' => ['required', 'numeric', 'gt:min_qty'], 'multiple' => ['nullable', 'numeric', 'gt:0'],
            'is_active' => ['boolean'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();
        $data['pick_bin_id'] = ! empty($data['pick_bin']) ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('uuid', $data['pick_bin'])->firstOrFail()->id : null;
        $data['source_warehouse_id'] = ! empty($data['source_warehouse']) ? FinWarehouse::query()->where('uuid', $data['source_warehouse'])->firstOrFail()->id : null;
        $rule = $uuid ? ReplenishmentRule::query()->where('uuid', $uuid)->firstOrFail() : null;

        return $this->attempt(fn () => $replenishment->saveRule($warehouse, $item, $data, $rule), 'Rule saved.');
    }

    public function generate(Request $request, Replenishment $replenishment): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $made = 0;

        return $this->attempt(function () use ($replenishment, $request, &$made) {
            $made = $replenishment->generate(null, $request->user());
        }, 'Replenishment checked.');
    }

    public function actTask(Request $request, string $uuid, Replenishment $replenishment): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $task = ReplenishmentTask::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['complete', 'fail'])], 'qty' => ['nullable', 'numeric', 'gt:0'],
            'item_scan' => ['nullable', 'string', 'max:200'], 'to_scan' => ['nullable', 'string', 'max:100'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $data['action'] === 'complete'
            ? $replenishment->complete($task, (float) ($data['qty'] ?? ((float) $task->qty - (float) $task->done_qty)), $request->user(),
                ['item_scan' => $data['item_scan'] ?? null, 'to_scan' => $data['to_scan'] ?? null])
            : $replenishment->fail($task, (string) ($data['reason'] ?? '')), 'Task updated.');
    }

    public function raiseRequest(Request $request, Replenishment $replenishment): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_REPLENISHMENT), 403);
        $data = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*' => ['numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:1000']]);
        $pr = null;
        $response = $this->attempt(function () use ($replenishment, $data, $request, &$pr) {
            $pr = $replenishment->raiseRequest($data['lines'], $request->user(), $data['note'] ?? null);
        }, 'Purchase request raised.');

        return $pr ? back()->with('success', "Purchase request {$pr->request_number} raised as a draft in Procurement.") : $response;
    }

    private function warehouseOrDefault(Request $request): ?FinWarehouse
    {
        return $request->filled('warehouse')
            ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail()
            : FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->first();
    }

    private function warehouseOptions(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"])->all();
    }
}
