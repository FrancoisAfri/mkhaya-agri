<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Models\RoutingApproval;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\RoutingCalculator;
use App\Modules\Operations\Manufacturing\Services\RoutingVersioning;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Routings (MFG-003). Every write that touches the operations goes through a draft; an approved
 * routing offers Revise, never Edit, and the database refuses the edit regardless.
 */
class RoutingController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly RoutingVersioning $versioning,
        private readonly RoutingCalculator $calculator,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Routing::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Routing::STATUSES)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return Inertia::render('Modules/Manufacturing/Engineering/Routings', [
            'routings' => Routing::query()
                ->with('item.stockItem:id,code,name')
                ->withCount('operations')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereHas('item.stockItem',
                    fn ($s) => $s->where('code', 'ilike', "%{$term}%")->orWhere('name', 'ilike', "%{$term}%")))
                ->orderByDesc('updated_at')
                ->paginate(25)->withQueryString()
                ->through(fn (Routing $r) => $this->summarise($r)),
            'filters' => $filters,
            'statuses' => Routing::STATUSES,
            'manufacturable' => Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)
                ->with('stockItem:id,code,name')->orderBy('id')->get()
                ->map(fn (Item $i) => ['id' => $i->uuid, 'code' => $i->stockItem?->code, 'name' => $i->stockItem?->name]),
            'can' => ['manage' => $request->user()->can('create', Routing::class)],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $routing = $this->find($uuid);
        $this->authorize('view', $routing);

        $routing->load(['operations.workCentre:id,uuid,code,name', 'operations.alternates.workCentre:id,code,name',
            'approvals', 'supersededBy:id,uuid,revision']);

        $user = $request->user();

        return Inertia::render('Modules/Manufacturing/Engineering/RoutingShow', [
            // MFG-023: the record's documents, through Document Management's own visibility rules.
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($routing, $request->user()),
            'routing' => $this->detail($routing),
            'work_centres' => WorkCentre::query()->where('is_active', true)->with('factory:id,code')->orderBy('code')
                ->get()->map(fn (WorkCentre $c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'factory' => $c->factory?->code,
                ]),
            'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($f) => ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name]),
            'permissions' => RoleCatalog::all(),
            'can' => [
                'edit' => $user->can('update', $routing),
                'approve' => $user->can('approve', $routing),
                'revise' => $user->can('revise', $routing),
                'obsolete' => $user->can('obsolete', $routing),
                'delete' => $user->can('delete', $routing),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Routing::class);

        $data = $request->validate([
            'item_uuid' => ['required', 'string', $this->tenantExists('mfg_items', 'uuid', true)],
            'name' => ['nullable', 'string', 'max:255'],
            'min_batch_size' => ['nullable', 'numeric', 'gt:0'],
            'max_batch_size' => ['nullable', 'numeric', 'gt:0', Rule::when(fn ($input) => filled($input->min_batch_size), 'gte:min_batch_size')],
            'standard_yield_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'yield_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $item = Item::query()->where('uuid', $data['item_uuid'])->firstOrFail();

        if (! $item->isManufactured()) {
            return back()->with('error', 'Only a finished product, intermediate or subassembly can have a routing.');
        }

        $routing = Routing::create([
            'company_id' => $item->company_id, 'item_id' => $item->id,
            'revision' => (int) Routing::withoutGlobalScopes()->where('item_id', $item->id)->max('revision') + 1,
            'name' => $data['name'] ?? null, 'status' => Routing::DRAFT,
            'min_batch_size' => $data['min_batch_size'] ?? null, 'max_batch_size' => $data['max_batch_size'] ?? null,
            'standard_yield_percent' => $data['standard_yield_percent'] ?? 100,
            'yield_tolerance_percent' => $data['yield_tolerance_percent'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('manufacturing.engineering.routings.show', $routing->uuid)
            ->with('success', "Routing revision {$routing->revision} drafted. Add its operations, then submit it.");
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('update', $routing);

        $routing->update($request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'min_batch_size' => ['nullable', 'numeric', 'gt:0'],
            'max_batch_size' => ['nullable', 'numeric', 'gt:0', Rule::when(fn ($input) => filled($input->min_batch_size), 'gte:min_batch_size')],
            'standard_yield_percent' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'yield_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]));

        return back()->with('success', 'Draft updated.');
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('delete', $routing);
        $routing->delete();

        return redirect()->route('manufacturing.engineering.routings.index')->with('success', 'Draft discarded.');
    }

    // ── Operations ───────────────────────────────────────────────────────────────────────────

    public function storeOperation(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('update', $routing);

        $data = $request->validate($this->operationRules());
        $data['sequence'] ??= (int) $routing->operations()->max('sequence') + 10;

        if ($routing->operations()->where('sequence', $data['sequence'])->exists()) {
            return back()->with('error', "Another operation already uses sequence {$data['sequence']}.");
        }

        $routing->operations()->create($data + ['company_id' => $routing->company_id]);

        return back()->with('success', 'Operation added.');
    }

    public function updateOperation(Request $request, string $uuid, string $operationUuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('update', $routing);

        $op = $routing->operations()->where('uuid', $operationUuid)->firstOrFail();
        $data = $request->validate($this->operationRules(true));

        if (isset($data['sequence']) && $routing->operations()->where('sequence', $data['sequence'])->where('id', '!=', $op->id)->exists()) {
            return back()->with('error', "Another operation already uses sequence {$data['sequence']}.");
        }

        $op->update($data);

        return back()->with('success', 'Operation updated.');
    }

    public function destroyOperation(string $uuid, string $operationUuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('update', $routing);

        $routing->operations()->where('uuid', $operationUuid)->firstOrFail()->delete();

        return back()->with('success', 'Operation removed.');
    }

    public function storeAlternate(Request $request, string $uuid, string $operationUuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('update', $routing);

        $op = $routing->operations()->where('uuid', $operationUuid)->firstOrFail();

        $data = $request->validate([
            'work_centre_id' => ['required', 'integer', $this->tenantExists('mfg_work_centres', 'id', true)],
            'priority' => ['nullable', 'integer', 'min:1'],
            'run_minutes' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ((int) $data['work_centre_id'] === (int) $op->work_centre_id) {
            return back()->with('error', 'That is already the operation\'s own work centre.');
        }

        if ($op->alternates()->where('work_centre_id', $data['work_centre_id'])->exists()) {
            return back()->with('error', 'That work centre is already a permitted alternative.');
        }

        $op->alternates()->create($data + ['company_id' => $routing->company_id, 'priority' => $data['priority'] ?? 1]);

        return back()->with('success', 'Alternative work centre added.');
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────────────────────

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('update', $routing);

        return $this->attempt(fn () => $this->versioning->submit($routing, $request->user()->id,
            $request->string('comment')->toString() ?: null), 'Submitted for approval.');
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('approve', $routing);

        return $this->attempt(fn () => $this->versioning->approve($routing, $request->user()->id,
            $request->string('comment')->toString() ?: null), 'Routing approved and made the default.');
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('approve', $routing);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return $this->attempt(fn () => $this->versioning->reject($routing, $request->user()->id, $reason), 'Routing rejected.');
    }

    public function revise(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('revise', $routing);

        try {
            $draft = $this->versioning->revise($routing, $request->user()->id, $request->string('reason')->toString() ?: null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.engineering.routings.show', $draft->uuid)
            ->with('success', "Revision {$draft->revision} drafted from revision {$routing->revision}.");
    }

    public function obsolete(Request $request, string $uuid): RedirectResponse
    {
        $routing = $this->find($uuid);
        $this->authorize('obsolete', $routing);

        return $this->attempt(fn () => $this->versioning->obsolete($routing, $request->user()->id,
            $request->string('reason')->toString() ?: null), 'Routing retired.');
    }

    /** Lead time and capacity for a quantity -- JSON, so the screen can recalculate without reloading. */
    public function estimate(Request $request, string $uuid)
    {
        $routing = $this->find($uuid);
        $this->authorize('view', $routing);

        $input = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'factory' => ['nullable', 'string'],
            'start' => ['nullable', 'date'],
        ]);

        $factory = isset($input['factory']) ? Factory::query()->where('uuid', $input['factory'])->first() : null;

        try {
            return response()->json($this->calculator->estimate(
                $routing, (float) $input['quantity'], $factory,
                isset($input['start']) ? \Illuminate\Support\Carbon::parse($input['start']) : null,
            ));
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function operationRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'sequence' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'code' => ['nullable', 'string', 'max:32'],
            'name' => [$req, 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'work_centre_id' => ['nullable', 'integer', $this->tenantExists('mfg_work_centres', 'id', true)],
            'machine_type' => ['nullable', 'string', 'max:64'],
            'setup_minutes' => ['nullable', 'numeric', 'min:0'],
            'run_minutes' => ['nullable', 'numeric', 'min:0'],
            'run_basis' => ['nullable', Rule::in([RoutingOperation::PER_UNIT, RoutingOperation::PER_BATCH])],
            'move_minutes' => ['nullable', 'numeric', 'min:0'],
            'labour_headcount' => ['nullable', 'numeric', 'min:0'],
            'labour_percent_of_time' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'expected_yield_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'parallel_group' => ['nullable', 'string', 'max:16'],
            'is_mandatory' => ['nullable', 'boolean'],
            'is_inspection_point' => ['nullable', 'boolean'],
            'inspection_plan_reference' => ['nullable', 'string', 'max:255'],
            'is_approval_gate' => ['nullable', 'boolean'],
            'gate_permission' => ['nullable', 'string', 'max:128'],
            'is_subcontracted' => ['nullable', 'boolean'],
            'subcontractor_supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id')],
            'subcontract_lead_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'subcontract_cost_per_unit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function attempt(callable $work, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function find(string $uuid): Routing
    {
        return Routing::query()->with('item.stockItem:id,code,name')->where('uuid', $uuid)->firstOrFail();
    }

    private function summarise(Routing $r): array
    {
        return [
            'id' => $r->uuid,
            'revision' => (int) $r->revision,
            'name' => $r->name,
            'status' => $r->status,
            'is_default' => (bool) $r->is_default,
            'product_id' => $r->item?->uuid,
            'product_code' => $r->item?->stockItem?->code,
            'product_name' => $r->item?->stockItem?->name,
            'operations_count' => (int) ($r->operations_count ?? $r->operations()->count()),
            'min_batch_size' => $r->min_batch_size !== null ? (float) $r->min_batch_size : null,
            'max_batch_size' => $r->max_batch_size !== null ? (float) $r->max_batch_size : null,
            'standard_yield_percent' => (float) $r->standard_yield_percent,
            'yield_tolerance_percent' => $r->yield_tolerance_percent !== null ? (float) $r->yield_tolerance_percent : null,
            'effective_from' => $r->effective_from?->toDateString(),
            'effective_to' => $r->effective_to?->toDateString(),
            'is_editable' => $r->isEditable(),
        ];
    }

    private function detail(Routing $r): array
    {
        return $this->summarise($r) + [
            'notes' => $r->notes,
            'superseded_by' => $r->supersededBy ? ['id' => $r->supersededBy->uuid, 'revision' => (int) $r->supersededBy->revision] : null,
            'operations' => $r->operations->map(fn (RoutingOperation $op) => [
                'id' => $op->uuid,
                'sequence' => (int) $op->sequence,
                'code' => $op->code,
                'name' => $op->name,
                'instructions' => $op->instructions,
                'work_centre_id' => $op->work_centre_id,
                'work_centre' => $op->workCentre ? "{$op->workCentre->code} — {$op->workCentre->name}" : null,
                'machine_type' => $op->machine_type,
                'setup_minutes' => (float) $op->setup_minutes,
                'run_minutes' => (float) $op->run_minutes,
                'run_basis' => $op->run_basis,
                'move_minutes' => (float) $op->move_minutes,
                'labour_headcount' => (float) $op->labour_headcount,
                'labour_percent_of_time' => (float) $op->labour_percent_of_time,
                'expected_yield_percent' => (float) $op->expected_yield_percent,
                'parallel_group' => $op->parallel_group,
                'is_mandatory' => (bool) $op->is_mandatory,
                'is_inspection_point' => (bool) $op->is_inspection_point,
                'inspection_plan_reference' => $op->inspection_plan_reference,
                'is_approval_gate' => (bool) $op->is_approval_gate,
                'gate_permission' => $op->gate_permission,
                'is_subcontracted' => (bool) $op->is_subcontracted,
                'subcontract_lead_days' => $op->subcontract_lead_days,
                'subcontract_cost_per_unit' => $op->subcontract_cost_per_unit !== null ? (float) $op->subcontract_cost_per_unit : null,
                // What must be finished before this may complete -- the routing engine's rule, shown.
                'requires' => $this->calculator->prerequisites($op)->pluck('sequence')->all(),
                'alternates' => $op->alternates->map(fn ($a) => [
                    'id' => $a->uuid, 'work_centre' => $a->workCentre ? "{$a->workCentre->code} — {$a->workCentre->name}" : null,
                    'priority' => (int) $a->priority, 'run_minutes' => $a->run_minutes !== null ? (float) $a->run_minutes : null,
                ])->all(),
            ])->all(),
            'history' => $r->approvals->map(fn (RoutingApproval $a) => [
                'id' => $a->uuid, 'action' => $a->action, 'acted_at' => $a->acted_at?->toIso8601String(), 'comment' => $a->comment,
            ])->all(),
        ];
    }
}
