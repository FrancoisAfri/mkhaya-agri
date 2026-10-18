<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Services\PutawayAdvisor;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-040 to WMS-042: put-away tasks, guided execution, the exception queue and capacity. */
class PutawayController extends Controller
{
    use RefusesPolitely;

    public function __construct(private readonly PutawayTasks $tasks) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $view = $request->input('view', 'open');
        $statuses = match ($view) {
            'exceptions' => [PutawayTask::FAILED, PutawayTask::PARTIAL],
            'done' => [PutawayTask::DONE, PutawayTask::CANCELLED],
            default => [PutawayTask::OPEN, PutawayTask::IN_PROGRESS, PutawayTask::PARTIAL],
        };
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;

        $list = PutawayTask::query()->with(['item:id,uuid,code,name,uom', 'fromBin:id,code', 'suggestedBin:id,code', 'toBin:id,code', 'lot:id,lot_number', 'warehouse:id,code', 'assignee.person'])
            ->whereIn('status', $statuses)
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->orderByDesc('priority')->orderBy('id')->paginate(50)->withQueryString();

        return Inertia::render('Modules/Warehouse/Putaway/Index', [
            'tasks' => $list->through(fn (PutawayTask $t) => $this->row($t)),
            'filters' => ['view' => $view, 'warehouse' => $warehouse?->uuid, 'mine' => $request->boolean('mine')],
            'counts' => [
                'open' => PutawayTask::query()->whereIn('status', [PutawayTask::OPEN, PutawayTask::IN_PROGRESS])->count(),
                'exceptions' => PutawayTask::query()->whereIn('status', [PutawayTask::FAILED, PutawayTask::PARTIAL])->count(),
            ],
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'can' => ['move' => $request->user()->can(RoleCatalog::MOVE_STOCK)],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $task = PutawayTask::query()->where('uuid', $uuid)->with(['item', 'fromBin', 'suggestedBin', 'toBin', 'lot', 'warehouse'])->firstOrFail();

        return Inertia::render('Modules/Warehouse/Putaway/Show', [
            'task' => $this->row($task) + [
                'reasons' => $task->suggestion_reasons ?? [],
                'policy' => $task->warehouse->policies['putaway'] ?? 'SUGGESTED',
                'scan_required' => (bool) ($task->warehouse->policies['scan_to_confirm'] ?? true),
                'suggested_barcode' => $task->suggestedBin?->barcode,
            ],
            'bins' => FinStockBin::query()->where('warehouse_id', $task->warehouse_id)->where('level', 'BIN')->where('is_active', true)
                ->where('status', FinStockBin::OPEN)->orderBy('code')->get(['uuid', 'code', 'kind'])
                ->map(fn ($b) => ['id' => $b->uuid, 'title' => "{$b->code} ({$b->kind})"]),
            'can' => ['move' => $request->user()->can(RoleCatalog::MOVE_STOCK), 'override' => $request->user()->can(RoleCatalog::OVERRIDE_PUTAWAY)],
        ]);
    }

    /** A put-away for stock that is somewhere it does not live -- a staging lane, an unbinned receipt. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'item' => ['required', 'string'], 'qty' => ['required', 'numeric', 'gt:0'],
            'from_bin' => ['nullable', 'string'], 'lot_id' => ['nullable', 'integer'], 'stock_status' => ['nullable', Rule::in(FinStockPosition::STATUSES)],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();
        $from = ! empty($data['from_bin']) ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('uuid', $data['from_bin'])->firstOrFail() : null;

        return $this->attempt(fn () => $this->tasks->create($warehouse, $item, (float) $data['qty'], [
            'from_bin_id' => $from?->id, 'lot_id' => $data['lot_id'] ?? null, 'stock_status' => $data['stock_status'] ?? FinStockPosition::AVAILABLE,
            'source_type' => 'MANUAL',
        ]), 'Put-away task created.');
    }

    public function act(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MOVE_STOCK), 403);
        $task = PutawayTask::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'complete', 'fail', 'cancel', 'resuggest'])],
            'bin' => ['nullable', 'string'], 'qty' => ['nullable', 'numeric', 'gt:0'],
            'item_scan' => ['nullable', 'string', 'max:200'], 'bin_scan' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();

        return $this->attempt(fn () => match ($data['action']) {
            'start' => $this->tasks->start($task, $user),
            'complete' => $this->tasks->complete($task,
                FinStockBin::query()->where('warehouse_id', $task->warehouse_id)->where('uuid', (string) ($data['bin'] ?? ''))->firstOrFail(),
                (float) ($data['qty'] ?? ((float) $task->qty - (float) $task->done_qty)), $user,
                ['item_scan' => $data['item_scan'] ?? null, 'bin_scan' => $data['bin_scan'] ?? null]),
            'fail' => $this->tasks->fail($task, $user, (string) ($data['reason'] ?? '')),
            'cancel' => $this->tasks->cancel($task, $user, (string) ($data['reason'] ?? '')),
            default => $this->tasks->resuggest($task),
        }, 'Task updated.');
    }

    /** WMS-042: configured capacity against what is held, by warehouse and zone, with what is at risk. */
    public function capacity(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        // Raw queries: named by company, because a platform administrator has no tenant stamped.
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()?->company_id;
        $rows = DB::table('fin_stock_bins as b')
            ->join('fin_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin(DB::raw('(SELECT bin_id, SUM(qty) AS qty FROM fin_stock_positions GROUP BY bin_id) p'), 'p.bin_id', '=', 'b.id')
            ->where('b.level', 'BIN')->where('b.is_active', true)
            ->when($cid, fn ($q) => $q->where('b.company_id', $cid))
            ->groupBy('w.id', 'w.code', 'w.name', 'w.capacity_basis', 'w.capacity_value')
            ->selectRaw('w.code, w.name, w.capacity_basis, w.capacity_value, COUNT(*) AS bins')
            ->selectRaw('COUNT(CASE WHEN COALESCE(p.qty, 0) <> 0 THEN 1 END) AS occupied')
            ->selectRaw('SUM(b.max_units) AS unit_capacity, SUM(CASE WHEN b.max_units IS NOT NULL THEN COALESCE(p.qty, 0) END) AS units_in_limited_bins')
            ->selectRaw('SUM(COALESCE(b.max_pallets, 0)) AS pallet_positions')
            ->selectRaw("COUNT(CASE WHEN b.max_units IS NOT NULL AND COALESCE(p.qty, 0) >= b.max_units * 0.9 THEN 1 END) AS near_full")
            ->selectRaw("COUNT(CASE WHEN b.max_units IS NOT NULL AND COALESCE(p.qty, 0) > b.max_units THEN 1 END) AS over")
            ->orderBy('w.code')->get();

        // Items that only fit a few bins: heavier, larger or hazardous than most locations allow.
        $restricted = DB::table('mfg_items as m')->join('fin_stock_items as i', 'i.id', '=', 'm.stock_item_id')
            ->leftJoin('wms_item_profiles as pr', 'pr.stock_item_id', '=', 'i.id')
            ->when($cid, fn ($q) => $q->where('i.company_id', $cid))
            ->where(fn ($q) => $q->where('m.weight_kg', '>=', 25)->orWhereNotNull('pr.hazard_class')->orWhereNotNull('pr.temperature_max_c'))
            ->limit(50)->get(['i.uuid', 'i.code', 'i.name', 'm.weight_kg', 'pr.hazard_class', 'pr.temperature_min_c', 'pr.temperature_max_c']);

        return Inertia::render('Modules/Warehouse/Putaway/Capacity', [
            'warehouses' => $rows->map(fn ($r) => (array) $r + [
                'occupancy' => $r->unit_capacity ? round((float) $r->units_in_limited_bins / (float) $r->unit_capacity, 4) : null,
            ]),
            'restricted_items' => $restricted->map(fn ($r) => (array) $r + [
                'eligible_bins' => DB::table('fin_stock_bins')->when($cid, fn ($q) => $q->where('company_id', $cid))->where('level', 'BIN')->where('is_active', true)->where('status', 'OPEN')
                    ->when($r->hazard_class, fn ($q) => $q->where('hazmat_allowed', true))
                    ->when($r->temperature_max_c !== null, fn ($q) => $q->where('requires_temperature_monitoring', true))
                    ->when((float) $r->weight_kg >= 25, fn ($q) => $q->where(fn ($w) => $w->whereNull('max_weight_kg')->orWhere('max_weight_kg', '>=', $r->weight_kg)))
                    ->count(),
            ]),
        ]);
    }

    private function row(PutawayTask $t): array
    {
        return [
            'id' => $t->uuid, 'number' => $t->number, 'status' => $t->status, 'priority' => $t->priority,
            'warehouse' => $t->warehouse?->code, 'item' => $t->item?->code, 'item_name' => $t->item?->name, 'uom' => $t->item?->uom,
            'lot' => $t->lot?->lot_number, 'stock_status' => $t->stock_status, 'qty' => (float) $t->qty, 'done_qty' => (float) $t->done_qty,
            'from' => $t->fromBin?->code, 'suggested' => $t->suggestedBin?->code, 'suggested_id' => $t->suggestedBin?->uuid, 'to' => $t->toBin?->code,
            'source' => $t->source_type, 'exception_note' => $t->exception_note,
            'assignee' => $t->assignee?->person ? trim($t->assignee->person->first_name.' '.$t->assignee->person->surname) : $t->assignee?->email,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
