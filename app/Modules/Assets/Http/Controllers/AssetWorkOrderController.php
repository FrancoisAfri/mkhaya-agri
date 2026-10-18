<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CreateWorkOrderAction;
use App\Modules\Assets\Actions\TransitionWorkOrderStatusAction;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetWorkOrder;
use App\Modules\Assets\Models\AssetWorkOrderPart;
use App\Modules\Assets\Models\AssetWorkOrderTask;
use App\Modules\Assets\Services\WorkOrderCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AssetWorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderCostService $costs) {}

    private const PRIORITY_META = [
        'low'      => ['label' => 'Low',      'color' => 'grey'],
        'medium'   => ['label' => 'Medium',   'color' => 'info'],
        'high'     => ['label' => 'High',     'color' => 'warning'],
        'critical' => ['label' => 'Critical', 'color' => 'error'],
    ];

    private const STATUS_META = [
        'open'        => ['label' => 'Open',        'color' => 'primary'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'info'],
        'on_hold'     => ['label' => 'On Hold',     'color' => 'warning'],
        'completed'   => ['label' => 'Completed',   'color' => 'success'],
        'cancelled'   => ['label' => 'Cancelled',   'color' => 'error'],
    ];

    private const TYPE_META = [
        'corrective' => 'Corrective',
        'preventive' => 'Preventive',
        'inspection' => 'Inspection',
    ];

    // ── Listing ───────────────────────────────────────────────────────────────
    public function index(Request $request): Response
    {
        $orders = AssetWorkOrder::query()
            ->with(['asset:id,uuid,name,asset_number', 'assignee.person:id,user_id,first_name,surname'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->priority))
            ->when($request->filled('asset'), fn ($q) => $q->whereHas('asset', fn ($a) => $a->where('uuid', $request->asset)))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($w) use ($request) {
                $w->where('title', 'ilike', "%{$request->search}%")
                  ->orWhere('work_order_number', 'ilike', "%{$request->search}%");
            }))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AssetWorkOrder $wo) => $this->summarize($wo));

        $stats = [
            'open'        => AssetWorkOrder::where('status', 'open')->count(),
            'in_progress' => AssetWorkOrder::where('status', 'in_progress')->count(),
            'on_hold'     => AssetWorkOrder::where('status', 'on_hold')->count(),
            'completed'   => AssetWorkOrder::where('status', 'completed')->count(),
        ];

        return Inertia::render('Modules/Assets/WorkOrders/Index', [
            'orders'  => $orders,
            'stats'   => $stats,
            'filters' => $request->only('status', 'priority', 'asset', 'search'),
            'options' => $this->options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/WorkOrders/Create', [
            'assets'  => Assets::query()->orderBy('name')->get(['uuid', 'name', 'asset_number'])
                ->map(fn ($a) => ['uuid' => $a->uuid, 'name' => $a->name, 'asset_number' => $a->asset_number]),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request, CreateWorkOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'asset_uuid'    => 'required|string|exists:assets,uuid',
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string|max:5000',
            'type'          => 'required|in:corrective,preventive,inspection',
            'priority'      => 'required|in:low,medium,high,critical',
            'scheduled_for' => 'nullable|date',
            'assigned_to'   => 'nullable|integer|exists:users,id',
        ]);

        $asset = Assets::where('uuid', $data['asset_uuid'])->firstOrFail();

        $wo = $action->execute([
            'asset_id'      => $asset->id,
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'type'          => $data['type'],
            'priority'      => $data['priority'],
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'assigned_to'   => $data['assigned_to'] ?? null,
        ], Auth::id());

        return redirect()
            ->route('assets.work-orders.show', $wo->uuid)
            ->with('success', "Work order {$wo->work_order_number} created.");
    }

    public function show(AssetWorkOrder $workOrder): Response
    {
        $workOrder->load([
            'asset:id,uuid,name,asset_number,asset_status',
            'assignee.person:id,user_id,first_name,surname',
            'requester.person:id,user_id,first_name,surname',
            'tasks.doneBy.person:id,user_id,first_name,surname',
            'parts',
        ]);

        return Inertia::render('Modules/Assets/WorkOrders/Show', [
            'order'   => $this->detail($workOrder),
            'options' => $this->options(),
        ]);
    }

    public function update(Request $request, AssetWorkOrder $workOrder): RedirectResponse
    {
        abort_unless($workOrder->isEditable(), 422, 'A completed or cancelled work order cannot be edited.');

        $data = $request->validate([
            'title'         => 'sometimes|required|string|max:255',
            'description'   => 'nullable|string|max:5000',
            'type'          => 'sometimes|required|in:corrective,preventive,inspection',
            'priority'      => 'sometimes|required|in:low,medium,high,critical',
            'scheduled_for' => 'nullable|date',
            'assigned_to'   => 'nullable|integer|exists:users,id',
        ]);

        $workOrder->fill($data)->save();

        return back()->with('success', 'Work order updated.');
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────
    public function addTask(Request $request, AssetWorkOrder $workOrder): RedirectResponse
    {
        abort_unless($workOrder->isEditable(), 422, 'Cannot add tasks to a closed work order.');

        $data = $request->validate([
            'description' => 'required|string|max:500',
        ]);

        $workOrder->tasks()->create([
            'description' => $data['description'],
            'sequence'    => (int) $workOrder->tasks()->max('sequence') + 1,
        ]);

        return back()->with('success', 'Task added.');
    }

    public function toggleTask(AssetWorkOrder $workOrder, AssetWorkOrderTask $task): RedirectResponse
    {
        abort_unless($task->work_order_id === $workOrder->id, 404);
        abort_unless($workOrder->isEditable(), 422, 'Cannot change tasks on a closed work order.');

        if ($task->is_done) {
            $task->update(['is_done' => false, 'done_by' => null, 'done_at' => null]);
        } else {
            $task->update(['is_done' => true, 'done_by' => Auth::id(), 'done_at' => now()]);
        }

        return back();
    }

    public function removeTask(AssetWorkOrder $workOrder, AssetWorkOrderTask $task): RedirectResponse
    {
        abort_unless($task->work_order_id === $workOrder->id, 404);
        abort_unless($workOrder->isEditable(), 422, 'Cannot remove tasks from a closed work order.');

        $task->delete();

        return back()->with('success', 'Task removed.');
    }

    // ── Parts ─────────────────────────────────────────────────────────────────
    public function addPart(Request $request, AssetWorkOrder $workOrder): RedirectResponse
    {
        abort_unless($workOrder->isEditable(), 422, 'Cannot add parts to a closed work order.');

        $data = $request->validate([
            'part_id'     => 'nullable|integer',
            'description' => 'required|string|max:255',
            'quantity'    => 'required|numeric|min:0',
            'unit_cost'   => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($workOrder, $data) {
            $locked = AssetWorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            $locked->parts()->create([
                'part_id'     => $data['part_id'] ?? null,
                'description' => $data['description'],
                'quantity'    => $data['quantity'],
                'unit_cost'   => $data['unit_cost'],
                'line_cost'   => round((float) $data['quantity'] * (float) $data['unit_cost'], 2),
            ]);

            $this->costs->recalculateCosts($locked);
        });

        return back()->with('success', 'Part added.');
    }

    public function removePart(AssetWorkOrder $workOrder, AssetWorkOrderPart $part): RedirectResponse
    {
        abort_unless($part->work_order_id === $workOrder->id, 404);
        abort_unless($workOrder->isEditable(), 422, 'Cannot remove parts from a closed work order.');

        DB::transaction(function () use ($workOrder, $part) {
            $locked = AssetWorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $part->delete();
            $this->costs->recalculateCosts($locked);
        });

        return back()->with('success', 'Part removed.');
    }

    // ── Labour ────────────────────────────────────────────────────────────────
    public function setLabor(Request $request, AssetWorkOrder $workOrder): RedirectResponse
    {
        abort_unless($workOrder->isEditable(), 422, 'Cannot change labour on a closed work order.');

        $data = $request->validate([
            'labor_hours' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($workOrder, $data) {
            $locked = AssetWorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $locked->labor_hours = $data['labor_hours'];
            $locked->save();
            $this->costs->recalculateCosts($locked);
        });

        return back()->with('success', 'Labour hours updated.');
    }

    // ── Status flow ───────────────────────────────────────────────────────────
    public function start(AssetWorkOrder $workOrder, TransitionWorkOrderStatusAction $action): RedirectResponse
    {
        $action->start($workOrder);

        return back()->with('success', 'Work order started.');
    }

    public function hold(AssetWorkOrder $workOrder, TransitionWorkOrderStatusAction $action): RedirectResponse
    {
        $action->hold($workOrder);

        return back()->with('success', 'Work order put on hold.');
    }

    public function resume(AssetWorkOrder $workOrder, TransitionWorkOrderStatusAction $action): RedirectResponse
    {
        $action->resume($workOrder);

        return back()->with('success', 'Work order resumed.');
    }

    public function complete(Request $request, AssetWorkOrder $workOrder, TransitionWorkOrderStatusAction $action): RedirectResponse
    {
        $data = $request->validate([
            'resolution_notes' => 'nullable|string|max:5000',
            'force'            => 'nullable|boolean',
        ]);

        $action->complete($workOrder, $data['resolution_notes'] ?? null, (bool) ($data['force'] ?? false));

        return back()->with('success', 'Work order completed.');
    }

    public function cancel(Request $request, AssetWorkOrder $workOrder, TransitionWorkOrderStatusAction $action): RedirectResponse
    {
        $data = $request->validate([
            'resolution_notes' => 'nullable|string|max:5000',
        ]);

        $action->cancel($workOrder, $data['resolution_notes'] ?? null);

        return back()->with('success', 'Work order cancelled.');
    }

    // ── Serialization helpers ─────────────────────────────────────────────────
    private function options(): array
    {
        return [
            'priorities' => collect(self::PRIORITY_META)->map(fn ($m, $k) => ['value' => $k, 'label' => $m['label'], 'color' => $m['color']])->values(),
            'statuses'   => collect(self::STATUS_META)->map(fn ($m, $k) => ['value' => $k, 'label' => $m['label'], 'color' => $m['color']])->values(),
            'types'      => collect(self::TYPE_META)->map(fn ($label, $k) => ['value' => $k, 'label' => $label])->values(),
        ];
    }

    private function priorityChip(string $priority): array
    {
        $m = self::PRIORITY_META[$priority] ?? ['label' => ucfirst($priority), 'color' => 'grey'];

        return ['value' => $priority, 'label' => $m['label'], 'color' => $m['color']];
    }

    private function statusChip(string $status): array
    {
        $m = self::STATUS_META[$status] ?? ['label' => ucfirst($status), 'color' => 'grey'];

        return ['value' => $status, 'label' => $m['label'], 'color' => $m['color']];
    }

    private function summarize(AssetWorkOrder $wo): array
    {
        return [
            'uuid'              => $wo->uuid,
            'work_order_number' => $wo->work_order_number,
            'title'             => $wo->title,
            'type'              => self::TYPE_META[$wo->type] ?? ucfirst($wo->type),
            'priority'          => $this->priorityChip($wo->priority),
            'status'            => $this->statusChip($wo->status),
            'asset'             => $wo->asset ? [
                'uuid'         => $wo->asset->uuid,
                'name'         => $wo->asset->name,
                'asset_number' => $wo->asset->asset_number,
            ] : null,
            'assignee_name'     => $wo->assignee?->name,
            'scheduled_for'     => $wo->scheduled_for?->toDateString(),
            'total_cost'        => $wo->total_cost,
            'created_at'        => $wo->created_at?->toIso8601String(),
        ];
    }

    private function detail(AssetWorkOrder $wo): array
    {
        return array_merge($this->summarize($wo), [
            'description'      => $wo->description,
            'type_value'       => $wo->type,
            'priority_value'   => $wo->priority,
            'status_value'     => $wo->status,
            'editable'         => $wo->isEditable(),
            'all_tasks_done'   => $wo->allTasksDone(),
            'requester_name'   => $wo->requester?->name,
            'assigned_to'      => $wo->assigned_to,
            'started_at'       => $wo->started_at?->toIso8601String(),
            'completed_at'     => $wo->completed_at?->toIso8601String(),
            'resolution_notes' => $wo->resolution_notes,
            'labor_hours'      => $wo->labor_hours,
            'labor_cost'       => $wo->labor_cost,
            'parts_cost'       => $wo->parts_cost,
            'costs'            => [
                'labor' => $wo->labor_cost,
                'parts' => $wo->parts_cost,
                'total' => $wo->total_cost,
            ],
            'tasks' => $wo->tasks->map(fn (AssetWorkOrderTask $t) => [
                'uuid'        => $t->uuid,
                'description' => $t->description,
                'sequence'    => $t->sequence,
                'is_done'     => $t->is_done,
                'done_by'     => $t->doneBy?->name,
                'done_at'     => $t->done_at?->toIso8601String(),
            ])->values(),
            'parts' => $wo->parts->map(fn (AssetWorkOrderPart $p) => [
                'uuid'        => $p->uuid,
                'part_id'     => $p->part_id,
                'description' => $p->description,
                'quantity'    => $p->quantity,
                'unit_cost'   => $p->unit_cost,
                'line_cost'   => $p->line_cost,
            ])->values(),
        ]);
    }
}
