<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderEvent;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Production orders (MFG-006): raising them, moving them through the lifecycle, and recording what
 * happens to them on the floor.
 *
 * Every rule lives in the services; this controller authorizes, validates and reports. A refusal
 * from the service comes back as the message the service wrote, because it already says what is in
 * the way and what to do about it.
 */
class ProductionOrderController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly ProductionOrders $orders,
        private readonly ProductionOrderLifecycle $lifecycle,
        private readonly ProductionExecution $execution,
        private readonly StockLedger $stock,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProductionOrder::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...PO::STATUSES, 'OPEN'])],
            'factory' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $orders = PO::query()
            ->with(['item.stockItem:id,code,name', 'factory:id,uuid,code'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'OPEN'
                ? $q->whereNotIn('status', [PO::CLOSED, PO::CANCELLED, PO::REJECTED])
                : $q->where('status', $s))
            ->when($filters['factory'] ?? null, fn ($q, $f) => $q->whereHas('factory', fn ($x) => $x->where('uuid', $f)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('number', 'ilike', "%{$term}%")
                ->orWhere('demand_reference', 'ilike', "%{$term}%")
                ->orWhereHas('item.stockItem', fn ($s) => $s->where('code', 'ilike', "%{$term}%")->orWhere('name', 'ilike', "%{$term}%"))))
            ->orderByRaw("CASE WHEN status IN ('CLOSED','CANCELLED','REJECTED') THEN 1 ELSE 0 END")
            ->orderBy('due_date')->orderBy('priority')
            ->paginate(25)->withQueryString()
            ->through(fn (PO $o) => $this->summarise($o));

        $canCreate = $request->user()->can('create', PO::class);

        return Inertia::render('Modules/Manufacturing/Production/Orders', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => PO::STATUSES,
            'factories' => $this->factories(),
            // The create form's choices, only for somebody who may create.
            'products' => $canCreate ? $this->manufacturable() : [],
            'warehouses' => $canCreate ? $this->warehouses() : [],
            'people' => $canCreate ? $this->tenantPeopleOptions($request) : [],
            'can' => ['create' => $canCreate],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PO::class);

        $data = $request->validate([
            'item_uuid' => ['required', 'string', $this->tenantExists('mfg_items', 'uuid', true)],
            'factory_uuid' => ['required', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['required', 'date'],
            'start' => ['nullable', 'date', 'before_or_equal:due_date'],
            'warehouse_id' => ['required', 'integer', $this->tenantExists('fin_warehouses')],
            'component_warehouse_id' => ['nullable', 'integer', $this->tenantExists('fin_warehouses')],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'supervisor_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'demand_type' => ['nullable', Rule::in(['MANUAL', 'SALES_ORDER', 'STOCK', 'FORECAST', 'PROJECT'])],
            'demand_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_below_min_batch' => ['nullable', 'boolean'],
        ]);

        try {
            $order = $this->orders->create([
                'item' => Item::query()->where('uuid', $data['item_uuid'])->firstOrFail(),
                'factory' => Factory::query()->where('uuid', $data['factory_uuid'])->firstOrFail(),
                'warehouse' => FinWarehouse::query()->findOrFail($data['warehouse_id']),
                'component_warehouse' => isset($data['component_warehouse_id'])
                    ? FinWarehouse::query()->findOrFail($data['component_warehouse_id']) : null,
                'quantity' => (float) $data['quantity'],
                'due_date' => $data['due_date'],
                'start' => $data['start'] ?? null,
                'priority' => $data['priority'] ?? 3,
                'supervisor_id' => $data['supervisor_id'] ?? null,
                'demand_type' => $data['demand_type'] ?? 'MANUAL',
                'demand_reference' => $data['demand_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'allow_below_min_batch' => (bool) ($data['allow_below_min_batch'] ?? false),
            ], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.production.orders.show', $order->uuid)
            ->with('success', "Order {$order->number} drafted from BOM and routing in force on its start date.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $order = $this->find($uuid);
        $this->authorize('view', $order);

        $user = $request->user();
        $order->load(['components.stockItem', 'operations.workCentre:id,code,name', 'events',
            'bom:id,uuid,revision', 'routing:id,uuid,revision', 'warehouse:id,code,name',
            'componentWarehouse:id,code,name', 'supervisor']);

        $actors = \App\Core\User\Models\User::query()->with('person:id,user_id,first_name,surname')
            ->whereIn('id', $order->events->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        $warehouseId = $order->component_warehouse_id ?? $order->warehouse_id;
        $prechecks = in_array($order->status, [PO::DRAFT, PO::PLANNED], true);
        $reconcile = in_array($order->status, [PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::COMPLETED, PO::CLOSED], true);

        return Inertia::render('Modules/Manufacturing/Production/OrderShow', [
            // Who it is for and where it went (MFG-017): the sales order, its deliveries and invoice, read from Finance.
            'customer' => $user->can('viewAny', \App\Modules\Operations\Manufacturing\Models\CustomerCommitment::class)
                ? app(\App\Modules\Operations\Manufacturing\Services\CustomerOrders::class)->forOrder($order) : null,
            'subcontracts' => $user->can('viewAny', \App\Modules\Operations\Manufacturing\Models\SubcontractOrder::class)
                ? \App\Modules\Operations\Manufacturing\Models\SubcontractOrder::query()->where('production_order_id', $order->id)->with('supplier:id,company_name', 'operation:id,sequence,name')
                    ->orderBy('id')->get()->map(fn ($s) => ['id' => $s->uuid, 'number' => $s->number, 'status' => $s->status,
                        'supplier' => $s->supplier?->company_name, 'operation' => $s->operation ? "{$s->operation->sequence} {$s->operation->name}" : null,
                        'qty' => (float) $s->qty, 'received' => (float) $s->qty_received, 'due_date' => $s->due_date?->toDateString()])->values()
                : [],
            'order' => $this->summarise($order) + [
                'bom' => $order->bom ? ['id' => $order->bom->uuid, 'revision' => (int) $order->bom->revision] : null,
                'routing' => $order->routing ? ['id' => $order->routing->uuid, 'revision' => (int) $order->routing->revision] : null,
                'warehouse' => $order->warehouse ? "{$order->warehouse->code} — {$order->warehouse->name}" : null,
                'component_warehouse' => $order->componentWarehouse ? "{$order->componentWarehouse->code} — {$order->componentWarehouse->name}" : null,
                'supervisor' => $order->supervisor ? $this->personName($order->supervisor) : null,
                'demand_type' => $order->demand_type,
                'notes' => $order->notes,
                'planned_start' => $order->planned_start?->toDateString(),
                'planned_finish' => $order->planned_finish?->toDateString(),
                'status_before_hold' => $order->status_before_hold,
                'qty_scrapped' => (float) $order->qty_scrapped,
                'qty_reworked' => (float) $order->qty_reworked,
                'costs' => [
                    'estimated' => [
                        'material' => (float) $order->est_material_cost, 'labour' => (float) $order->est_labour_cost,
                        'machine' => (float) $order->est_machine_cost, 'overhead' => (float) $order->est_overhead_cost,
                        'subcontract' => (float) $order->est_subcontract_cost, 'total' => $order->estimatedTotal(),
                    ],
                    'actual' => [
                        'material' => (float) $order->act_material_cost, 'labour' => (float) $order->act_labour_cost,
                        'machine' => (float) $order->act_machine_cost, 'overhead' => (float) $order->act_overhead_cost,
                        'subcontract' => (float) $order->act_subcontract_cost, 'total' => $order->actualTotal(),
                    ],
                ],
                'wip' => $this->lifecycle->wipBalance($order),
                'approved_at' => $order->approved_at?->toIso8601String(),
                'released_at' => $order->released_at?->toIso8601String(),
                'started_at' => $order->started_at?->toIso8601String(),
                'completed_at' => $order->completed_at?->toIso8601String(),
                'closed_at' => $order->closed_at?->toIso8601String(),
            ],
            'components' => $order->components->map(fn (ProductionOrderComponent $c) => [
                'id' => $c->uuid,
                'code' => $c->stockItem?->code,
                'name' => $c->stockItem?->name,
                'kind' => $c->line_kind,
                'is_consumed' => $c->isConsumed(),
                'uom' => $c->uom,
                'required' => (float) $c->required_qty,
                'issued' => (float) $c->issued_qty,
                'returned' => (float) $c->returned_qty,
                'net_issued' => $c->netIssued(),
                'outstanding' => $c->outstanding(),
                'unit_cost' => (float) $c->est_unit_cost,
                'issued_value' => (float) $c->issued_value,
                // What the floor could take for this order now, its own reservation included.
                'available' => $c->stockItem && $c->isConsumed()
                    ? $this->stock->available($c->stockItem, $warehouseId, null, null, null, [PO::SOURCE_TYPE, $order->id]) : null,
                'lots' => $c->stockItem && $c->isConsumed() && $order->isActive()
                    ? collect($this->stock->breakdown($c->stockItem, $warehouseId))->where('usable', true)->whereNotNull('lot_id')
                        ->map(fn ($p) => ['id' => $p['lot_id'], 'lot' => $p['lot'], 'expires_on' => $p['expires_on'], 'qty' => $p['qty']])
                        ->values()->all()
                    : [],
            ])->values(),
            'operations' => $order->operations->map(fn (Op $op) => [
                'id' => $op->uuid,
                'sequence' => (int) $op->sequence,
                'name' => $op->name,
                'work_centre' => $op->workCentre ? "{$op->workCentre->code} — {$op->workCentre->name}" : ($op->machine_type ? "Any {$op->machine_type}" : null),
                'parallel_group' => $op->parallel_group,
                'is_mandatory' => (bool) $op->is_mandatory,
                'is_gate' => $op->isGate(),
                'is_inspection_point' => (bool) $op->is_inspection_point,
                'is_approval_gate' => (bool) $op->is_approval_gate,
                'is_subcontracted' => (bool) $op->is_subcontracted,
                'planned_minutes' => (float) $op->planned_minutes,
                'status' => $op->status,
                'gate_status' => $op->gate_status,
                'qty_completed' => (float) $op->qty_completed,
                'qty_rejected' => (float) $op->qty_rejected,
                'qty_scrapped' => (float) $op->qty_scrapped,
                'actual_machine_minutes' => (float) $op->actual_machine_minutes,
                'actual_labour_minutes' => (float) $op->actual_labour_minutes,
                'started_at' => $op->started_at?->toIso8601String(),
                'completed_at' => $op->completed_at?->toIso8601String(),
                'blocked_by' => $order->isActive() && $op->status !== Op::COMPLETED ? $this->execution->blockedBy($order, $op) : [],
            ])->values(),
            'events' => $order->events->map(fn (ProductionOrderEvent $e) => [
                'id' => $e->uuid,
                'action' => $e->action,
                'from' => $e->from_status,
                'to' => $e->to_status,
                'is_exception' => (bool) $e->is_exception,
                'reason' => $e->reason,
                'detail' => $e->detail,
                'actor' => $e->actor_id && isset($actors[$e->actor_id]) ? $this->personName($actors[$e->actor_id]) : null,
                'acted_at' => $e->acted_at?->toIso8601String(),
            ])->values(),
            // Every inspection opened for this order: in-process at its inspection points, final on its output.
            'inspections' => \App\Modules\Operations\Manufacturing\Models\Inspection::query()
                ->where('production_order_id', $order->id)->with(['lot:id,lot_number', 'operation:id,sequence,name'])->orderBy('id')->get()
                ->map(fn ($i) => [
                    'id' => $i->uuid, 'number' => $i->number, 'stage' => $i->stage, 'status' => $i->status, 'result' => $i->result,
                    'disposition' => $i->disposition, 'qty' => (float) $i->qty, 'lot' => $i->lot?->lot_number,
                    'operation' => $i->operation ? "{$i->operation->sequence} {$i->operation->name}" : null,
                ])->values(),
            'quality_hold' => app(\App\Modules\Operations\Manufacturing\Services\QualityControl::class)->blocking($order),
            // Standard against actual, with every variance and its method -- for those who may see costs.
            'cost_analysis' => $user->can(RoleCatalog::VIEW_COSTS) ? app(\App\Modules\Operations\Manufacturing\Services\CostAnalysis::class)->analyse($order) : null,
            'checks' => $prechecks ? $this->lifecycle->approvalChecks($order) : null,
            'reconciliation' => $reconcile ? $this->lifecycle->reconciliation($order) : null,
            'people' => $user->can('update', $order) ? $this->tenantPeopleOptions($request) : [],
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($order, $user),
            'can' => [
                'edit' => $user->can('update', $order),
                'plan' => $user->can('plan', $order),
                'unplan' => $user->can('unplan', $order),
                'approve' => $user->can('approve', $order),
                'reject' => $user->can('reject', $order),
                'reopen' => $user->can('reopen', $order),
                'release' => $user->can('release', $order),
                'hold' => $user->can('hold', $order),
                'resume' => $user->can('resume', $order),
                'cancel' => $user->can('cancel', $order),
                'send_to_quality' => $user->can('sendToQuality', $order),
                'return_to_production' => $user->can('returnToProduction', $order),
                'complete' => $user->can('complete', $order),
                'close' => $user->can('close', $order),
                'execute' => $user->can('execute', $order),
                'issue' => $user->can('issueMaterial', $order),
                'override' => $user->can(RoleCatalog::OVERRIDE_CONTROLS),
            ],
        ]);
    }

    /** A draft's quantity, dates, priority, supervisor and notes. A new quantity re-scales it. */
    public function update(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('update', $order);

        $data = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'due_date' => ['sometimes', 'date'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'supervisor_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'demand_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(function () use ($order, $data, $request) {
            $order->update(collect($data)->except('quantity')->all());

            if (isset($data['quantity']) && abs((float) $data['quantity'] - (float) $order->planned_qty) > 0.000001) {
                $this->orders->rescale($order, (float) $data['quantity'], $request->user());
            }
        }, 'Draft updated.');
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────────────────────

    public function plan(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('plan', $order);

        return $this->attempt(fn () => $this->lifecycle->plan($order, $request->user()), "Order {$order->number} planned.");
    }

    public function unplan(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('unplan', $order);

        return $this->attempt(fn () => $this->lifecycle->unplan($order, $request->user()), "Order {$order->number} is a draft again.");
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('approve', $order);
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->lifecycle->approve($order, $request->user(), $data['override_reason'] ?? null),
            "Order {$order->number} approved.");
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('reject', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->lifecycle->reject($order, $request->user(), $data['reason']), "Order {$order->number} rejected.");
    }

    public function reopen(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('reopen', $order);

        return $this->attempt(fn () => $this->lifecycle->reopen($order, $request->user()), "Order {$order->number} reopened as a draft.");
    }

    public function release(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('release', $order);

        return $this->attempt(fn () => $this->lifecycle->release($order, $request->user()),
            "Order {$order->number} released to the floor; material is reserved.");
    }

    public function hold(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('hold', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->lifecycle->hold($order, $request->user(), $data['reason']), "Order {$order->number} is on hold.");
    }

    public function resume(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('resume', $order);

        return $this->attempt(fn () => $this->lifecycle->resume($order, $request->user()), "Order {$order->number} resumed.");
    }

    public function cancel(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('cancel', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->lifecycle->cancel($order, $request->user(), $data['reason']), "Order {$order->number} cancelled.");
    }

    public function sendToQuality(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('sendToQuality', $order);

        return $this->attempt(fn () => $this->lifecycle->sendToQuality($order, $request->user()), "Order {$order->number} is in quality review.");
    }

    public function returnToProduction(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('returnToProduction', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->lifecycle->returnToProduction($order, $request->user(), $data['reason']),
            "Order {$order->number} is back in production for rework.");
    }

    public function complete(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('complete', $order);

        return $this->attempt(fn () => $this->lifecycle->complete($order, $request->user()), "Order {$order->number} completed.");
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('close', $order);

        return $this->attempt(fn () => $this->lifecycle->close($order, $request->user()),
            "Order {$order->number} closed; its work in progress is settled.");
    }

    // ── Material ─────────────────────────────────────────────────────────────────────────────

    public function issue(Request $request, string $uuid, string $componentUuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('issueMaterial', $order);
        $component = $this->component($order, $componentUuid);
        $data = $this->materialRules($request);

        return $this->attempt(fn () => $this->execution->issue($order, $component, (float) $data['qty'], $request->user(),
            array_filter([
                'lot_id' => $this->lotId($component, $data['lot_id'] ?? null),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'override_reason' => $data['override_reason'] ?? null,
            ])), 'Material issued to the order.');
    }

    public function returnMaterial(Request $request, string $uuid, string $componentUuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('issueMaterial', $order);
        $component = $this->component($order, $componentUuid);
        $data = $this->materialRules($request);

        return $this->attempt(fn () => $this->execution->returnMaterial($order, $component, (float) $data['qty'], $request->user(),
            array_filter([
                'lot_id' => $this->lotId($component, $data['lot_id'] ?? null),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ])), 'Material returned to stock.');
    }

    // ── Operations ───────────────────────────────────────────────────────────────────────────

    public function startOperation(Request $request, string $uuid, string $operationUuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('execute', $order);
        $op = $this->operation($order, $operationUuid);

        return $this->attempt(fn () => $this->execution->startOperation($order, $op, $request->user()), "Operation {$op->sequence} started.");
    }

    public function pauseOperation(Request $request, string $uuid, string $operationUuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('execute', $order);
        $op = $this->operation($order, $operationUuid);

        return $this->attempt(fn () => $this->execution->pauseOperation($order, $op), "Operation {$op->sequence} paused.");
    }

    public function completeOperation(Request $request, string $uuid, string $operationUuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('execute', $order);
        $op = $this->operation($order, $operationUuid);

        $data = $request->validate([
            'completed' => ['required', 'numeric', 'min:0'],
            'rejected' => ['nullable', 'numeric', 'min:0'],
            'scrapped' => ['nullable', 'numeric', 'min:0'],
            'reworked' => ['nullable', 'numeric', 'min:0'],
            'machine_minutes' => ['nullable', 'numeric', 'min:0'],
            'labour_minutes' => ['nullable', 'numeric', 'min:0'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->execution->completeOperation($order, $op, $data, $request->user(), $data['override_reason'] ?? null),
            "Operation {$op->sequence} complete.");
    }

    public function recordGate(Request $request, string $uuid, string $operationUuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('execute', $order);
        $op = $this->operation($order, $operationUuid);

        $data = $request->validate([
            'result' => ['required', Rule::in(['PASSED', 'FAILED'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->execution->recordGate($order, $op, $data['result'], $request->user(), $data['notes'] ?? null),
            $data['result'] === 'PASSED' ? "Gate at operation {$op->sequence} passed." : "Gate at operation {$op->sequence} failed; the operations behind it are stopped.");
    }

    // ── Output ───────────────────────────────────────────────────────────────────────────────

    public function receiveOutput(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('execute', $order);

        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->attempt(fn () => $this->execution->receiveOutput($order, (float) $data['qty'], $request->user(), array_filter([
            'lot_number' => $data['lot_number'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ])), 'Output received into stock.');
    }

    public function recordLoss(Request $request, string $uuid): RedirectResponse
    {
        $order = $this->find($uuid);
        $this->authorize('execute', $order);

        $data = $request->validate([
            'scrapped' => ['nullable', 'numeric', 'min:0'],
            'reworked' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->execution->recordLoss($order, (float) ($data['scrapped'] ?? 0),
            (float) ($data['reworked'] ?? 0), $request->user(), $data['reason'] ?? null), 'Loss recorded.');
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function attempt(callable $work, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function find(string $uuid): PO
    {
        return PO::query()->with(['item.stockItem:id,code,name', 'factory:id,uuid,code,name'])->where('uuid', $uuid)->firstOrFail();
    }

    private function component(PO $order, string $uuid): ProductionOrderComponent
    {
        return $order->components()->where('uuid', $uuid)->firstOrFail();
    }

    private function operation(PO $order, string $uuid): Op
    {
        return $order->operations()->where('uuid', $uuid)->firstOrFail();
    }

    private function materialRules(Request $request): array
    {
        return $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'lot_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * A lot named by the request must be a lot of this component's material. Lot ids are not
     * tenant-validated by a rule because lots are finance rows; checking them against the item is
     * stricter than checking the tenant, and it covers that too.
     */
    private function lotId(ProductionOrderComponent $component, ?int $lotId): ?int
    {
        if ($lotId === null) {
            return null;
        }

        $ok = \App\Modules\Finance\Accounting\Models\FinStockLot::withoutGlobalScopes()
            ->whereKey($lotId)->where('item_id', $component->stock_item_id)->exists();

        if (! $ok) {
            throw new DomainException('That lot is not a lot of this material.');
        }

        return $lotId;
    }

    private function summarise(PO $o): array
    {
        return [
            'id' => $o->uuid,
            'number' => $o->number,
            'status' => $o->status,
            'product_id' => $o->item?->uuid,
            'product_code' => $o->item?->stockItem?->code,
            'product_name' => $o->item?->stockItem?->name,
            'factory' => $o->factory?->code,
            'planned_qty' => (float) $o->planned_qty,
            'qty_completed' => (float) $o->qty_completed,
            'due_date' => $o->due_date?->toDateString(),
            'priority' => (int) $o->priority,
            'demand_reference' => $o->demand_reference,
            'estimated_total' => $o->estimatedTotal(),
            'is_overdue' => $o->isOverdue(),
        ];
    }

    private function manufacturable(): array
    {
        return Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)
            ->with('stockItem:id,code,name')->orderBy('id')->get()
            ->map(fn (Item $i) => ['id' => $i->uuid, 'code' => $i->stockItem?->code, 'name' => $i->stockItem?->name])
            ->values()->all();
    }

    private function factories(): array
    {
        return Factory::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name', 'default_warehouse_id'])
            ->map(fn ($f) => ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'default_warehouse_id' => $f->default_warehouse_id])
            ->values()->all();
    }

    private function warehouses(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name])->values()->all();
    }

    private function personName(\App\Core\User\Models\User $user): string
    {
        $p = $user->person;

        return $p ? trim("{$p->first_name} {$p->surname}") : (string) $user->email;
    }
}
