<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\LabourPlanner;
use App\Modules\Operations\WarehouseManagement\Services\PickAdvisor;
use App\Modules\Operations\WarehouseManagement\Services\Productivity;
use App\Modules\Operations\WarehouseManagement\Services\Simulator;
use App\Modules\Operations\WarehouseManagement\Services\Slotting;
use App\Modules\Operations\WarehouseManagement\Services\TaskBoard;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-130..135: the task board, labour, picking and slotting advice, performance and simulation. */
class WorkController extends Controller
{
    use RefusesPolitely;

    public function board(Request $request, TaskBoard $board): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $this->warehouse($request);
        $mine = $request->boolean('mine');

        return Inertia::render('Modules/Warehouse/Work/Board', [
            'tasks' => $warehouse ? $board->queue($warehouse, $request->input('type'), $mine ? $request->user()->id : null) : [],
            'warehouse' => $warehouse?->uuid, 'warehouses' => $this->warehouses(),
            'filters' => ['type' => $request->input('type'), 'mine' => $mine],
            'types' => TaskBoard::TYPES, 'priorities' => TaskBoard::PRIORITIES,
            'people' => $warehouse ? $this->staff($warehouse) : [],
            'can' => ['supervise' => $request->user()->can(RoleCatalog::SUPERVISE)],
        ]);
    }

    public function assign(Request $request, TaskBoard $board): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE), 403);
        $data = $request->validate(['type' => ['required', Rule::in(TaskBoard::TYPES)], 'ref' => ['required', 'string', 'max:64'], 'user_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(TaskBoard::PRIORITIES)], 'due_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:255']]);
        $to = ! empty($data['user_id']) ? User::query()->where('company_id', $this->cid($request))->findOrFail($data['user_id']) : null;

        return $this->attempt(fn () => $board->assign($data['type'], $data['ref'], $to, $data['priority'] ?? null, $data['due_at'] ?? null, $request->user(), $data['note'] ?? null),
            $to ? 'Task assigned.' : 'Task updated.');
    }

    public function taskHistory(Request $request, TaskBoard $board)
    {
        $this->authorize('viewAny', FinStockItem::class);

        return response()->json($board->history((string) $request->input('type'), (string) $request->input('ref')));
    }

    public function labour(Request $request, LabourPlanner $labour, PickAdvisor $picks): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $this->warehouse($request);

        return Inertia::render('Modules/Warehouse/Work/Labour', [
            'plan' => $warehouse ? $labour->plan($warehouse) : null,
            'picking' => $warehouse ? $picks->advise($warehouse) : null,
            'warehouse' => $warehouse?->uuid, 'warehouses' => $this->warehouses(),
            'can' => ['supervise' => $request->user()->can(RoleCatalog::SUPERVISE), 'pick' => $request->user()->can(RoleCatalog::PICK)],
        ]);
    }

    public function standards(Request $request, LabourPlanner $labour): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE), 403);
        $warehouse = FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['set', 'measure'])], 'activity' => ['required_if:action,set', 'nullable', Rule::in(array_keys(LabourPlanner::DEFAULTS))],
            'minutes_per_task' => ['required_if:action,set', 'nullable', 'numeric', 'min:0'], 'minutes_per_unit' => ['nullable', 'numeric', 'min:0']]);
        if ($data['action'] === 'measure') {
            $labour->remeasure($warehouse);

            return back()->with('success', 'Standards re-measured where there were enough timings.');
        }
        $labour->setStandard($warehouse, $data['activity'], (float) $data['minutes_per_task'], (float) ($data['minutes_per_unit'] ?? 0));

        return back()->with('success', 'Standard saved.');
    }

    public function slotting(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Work/Slotting', [
            'proposals' => DB::table('wms_slotting_proposals as p')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')->leftJoin('users as u', 'u.id', '=', 'p.created_by')
                ->when($this->cid($request), fn ($q, $c) => $q->where('p.company_id', $c))->orderByDesc('p.id')->limit(30)
                ->get(['p.uuid', 'p.status', 'p.moves', 'p.simulation', 'p.created_at', 'p.created_by', 'p.decision_note', 'w.code as warehouse', 'u.email as by'])
                ->map(fn ($p) => ['id' => $p->uuid, 'status' => $p->status, 'moves' => json_decode($p->moves, true), 'simulation' => $p->simulation ? json_decode($p->simulation, true) : null,
                    'warehouse' => $p->warehouse, 'by' => $p->by, 'mine' => (int) $p->created_by === $request->user()->id, 'at' => $p->created_at, 'note' => $p->decision_note]),
            'warehouses' => $this->warehouses(),
            'review_only_up_to' => Slotting::REVIEW_ONLY_UP_TO,
            'can' => ['supervise' => $request->user()->can(RoleCatalog::SUPERVISE), 'approve' => $request->user()->can(RoleCatalog::APPROVE_SLOTTING)],
        ]);
    }

    public function slottingAct(Request $request, Slotting $slotting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE) || $request->user()->can(RoleCatalog::APPROVE_SLOTTING), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['propose', 'simulate', 'approve', 'reject', 'apply'])], 'proposal' => ['nullable', 'string'],
            'warehouse' => ['nullable', 'string'], 'max_moves' => ['nullable', 'integer', 'between:1,200'], 'note' => ['nullable', 'string', 'max:500']]);
        $user = $request->user();
        abort_unless(in_array($data['action'], ['approve', 'reject'], true) ? $user->can(RoleCatalog::APPROVE_SLOTTING) : $user->can(RoleCatalog::SUPERVISE), 403);

        return $this->attempt(fn () => match ($data['action']) {
            'propose' => $slotting->propose(FinWarehouse::query()->where('uuid', $data['warehouse'] ?? '')->firstOrFail(), $user, (int) ($data['max_moves'] ?? 20)),
            'simulate' => $slotting->simulate((string) $data['proposal'], $user),
            'approve' => $slotting->decide((string) $data['proposal'], true, $data['note'] ?? null, $user),
            'reject' => $slotting->decide((string) $data['proposal'], false, $data['note'] ?? null, $user),
            'apply' => $slotting->apply((string) $data['proposal'], $user),
        }, match ($data['action']) { 'propose' => 'Proposal made.', 'simulate' => 'Simulated.', 'apply' => 'Put-away tasks created for the moves.', default => 'Decided.' });
    }

    public function performance(Request $request, Productivity $productivity): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $this->warehouse($request);
        $from = $request->input('from', now()->subDays(30)->toDateString());
        $to = $request->input('to', now()->toDateString());

        return Inertia::render('Modules/Warehouse/Work/Performance', [
            'report' => $warehouse ? $productivity->report($warehouse, $from, $to) : null,
            'filters' => ['from' => $from, 'to' => $to], 'warehouse' => $warehouse?->uuid, 'warehouses' => $this->warehouses(),
        ]);
    }

    public function simulations(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $warehouse = $this->warehouse($request);

        return Inertia::render('Modules/Warehouse/Work/Simulations', [
            'simulations' => DB::table('wms_simulations as s')->leftJoin('users as u', 'u.id', '=', 's.created_by')
                ->when($this->cid($request), fn ($q, $c) => $q->where('s.company_id', $c))->orderByDesc('s.id')->limit(30)
                ->get(['s.uuid', 's.kind', 's.inputs', 's.results', 's.trials', 's.seed', 's.created_at', 'u.email as by'])
                ->map(fn ($s) => ['id' => $s->uuid, 'kind' => $s->kind, 'inputs' => json_decode($s->inputs, true), 'results' => json_decode($s->results, true),
                    'trials' => $s->trials, 'seed' => (string) $s->seed, 'by' => $s->by, 'at' => $s->created_at]),
            'standards' => $warehouse ? app(LabourPlanner::class)->standards($warehouse) : [],
            'warehouse' => $warehouse?->uuid, 'warehouses' => $this->warehouses(),
            'can' => ['supervise' => $request->user()->can(RoleCatalog::SUPERVISE)],
        ]);
    }

    public function simulate(Request $request, Simulator $simulator): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['STAFFING', 'REPLENISHMENT'])], 'warehouse' => ['required', 'string'],
            'staff' => ['nullable', 'array'], 'staff.*' => ['integer', 'min:0', 'max:500'], 'demand_pct' => ['nullable', 'integer', 'between:-90,500'],
            'item' => ['required_if:kind,REPLENISHMENT', 'nullable', 'string'], 'min' => ['required_if:kind,REPLENISHMENT', 'nullable', 'numeric', 'min:0'],
            'max' => ['required_if:kind,REPLENISHMENT', 'nullable', 'numeric', 'gt:0'], 'lead_days' => ['nullable', 'integer', 'between:0,365'],
            'trials' => ['nullable', 'integer', 'between:50,5000'], 'seed' => ['nullable', 'integer', 'min:1']]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();

        return $this->attempt(fn () => $data['kind'] === 'STAFFING'
            ? $simulator->staffing($warehouse, ['staff' => $data['staff'] ?? [], 'demand_pct' => $data['demand_pct'] ?? 0, 'trials' => $data['trials'] ?? null, 'seed' => $data['seed'] ?? null], $request->user())
            : $simulator->replenishment($warehouse, ['item_id' => FinStockItem::query()->where('uuid', $data['item'])->firstOrFail()->id, 'min' => (float) $data['min'],
                'max' => (float) $data['max'], 'lead_days' => (int) ($data['lead_days'] ?? 0), 'trials' => $data['trials'] ?? null, 'seed' => $data['seed'] ?? null], $request->user()),
            'Simulation complete.');
    }

    private function warehouse(Request $request): ?FinWarehouse
    {
        return $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail()
            : FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->first();
    }

    private function warehouses(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"])->all();
    }

    private function staff(FinWarehouse $warehouse): array
    {
        return DB::table('wms_warehouse_staff as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.warehouse_id', $warehouse->id)->where('s.is_active', true)
            ->orderBy('u.email')->get(['u.id', 'u.email', 's.activities'])->map(fn ($s) => ['id' => $s->id, 'title' => $s->email, 'skills' => json_decode((string) $s->activities, true) ?: []])->all();
    }

    private function cid(Request $request): ?int
    {
        $id = app(CurrentCompany::class)->id() ?? $request->user()?->company_id;

        return $id ? (int) $id : null;
    }
}
