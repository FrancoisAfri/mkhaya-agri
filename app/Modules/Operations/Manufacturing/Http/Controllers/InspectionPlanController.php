<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\InspectionCharacteristic;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inspection plans (MFG-009): what is checked on receipt, at an operation, and before release, and
 * the limits each measurement is judged against.
 *
 * A plan can be edited freely: an inspection keeps a copy of the limits it was recorded against, so
 * changing a plan changes what is asked next time and never what was found last time.
 */
class InspectionPlanController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InspectionPlan::class);

        $filters = $request->validate([
            'stage' => ['nullable', Rule::in(InspectionPlan::STAGES)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $canManage = $request->user()->can('create', InspectionPlan::class);

        return Inertia::render('Modules/Manufacturing/Quality/Plans', [
            'plans' => InspectionPlan::query()
                ->with(['item.stockItem:id,code,name', 'stockItem:id,code,name', 'routingOperation:id,sequence,name'])
                ->withCount('characteristics')
                ->when($filters['stage'] ?? null, fn ($q, $s) => $q->where('stage', $s))
                ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$t}%")->orWhere('name', 'ilike', "%{$t}%")))
                ->orderBy('stage')->orderBy('code')
                ->paginate(25)->withQueryString()
                ->through(fn (InspectionPlan $p) => $this->summarise($p)),
            'filters' => $filters,
            'stages' => InspectionPlan::STAGES,
            'options' => $canManage ? $this->options() : null,
            'can' => ['manage' => $canManage],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', InspectionPlan::class);
        $data = $this->validatePlan($request);

        $plan = InspectionPlan::create($data + [
            'company_id' => $this->tenantId($request), 'created_by' => $request->user()->id,
        ]);

        return redirect()->route('manufacturing.quality.plans.show', $plan->uuid)
            ->with('success', "Plan {$plan->code} created. Add what it checks.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $plan = $this->find($uuid);
        $this->authorize('view', $plan);
        $plan->load('characteristics');
        $canManage = $request->user()->can('update', $plan);

        return Inertia::render('Modules/Manufacturing/Quality/PlanShow', [
            'plan' => $this->summarise($plan) + [
                'notes' => $plan->notes,
                'item_uuid' => $plan->item?->uuid,
                'stock_item_id' => $plan->stock_item_id,
                'routing_operation_id' => $plan->routingOperation?->uuid,
                'characteristics' => $plan->characteristics->map(fn (InspectionCharacteristic $c) => [
                    'id' => $c->uuid, 'sequence' => (int) $c->sequence, 'name' => $c->name, 'kind' => $c->kind, 'uom' => $c->uom,
                    'target' => $c->target !== null ? (float) $c->target : null,
                    'lower_limit' => $c->lower_limit !== null ? (float) $c->lower_limit : null,
                    'upper_limit' => $c->upper_limit !== null ? (float) $c->upper_limit : null,
                    'is_critical' => (bool) $c->is_critical, 'method' => $c->method,
                ])->values(),
            ],
            'stages' => InspectionPlan::STAGES,
            'options' => $canManage ? $this->options() : null,
            'can' => ['manage' => $canManage],
        ]);
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $plan = $this->find($uuid);
        $this->authorize('update', $plan);
        $plan->update($this->validatePlan($request, $plan));

        return back()->with('success', 'Plan saved.');
    }

    public function storeCharacteristic(Request $request, string $uuid): RedirectResponse
    {
        $plan = $this->find($uuid);
        $this->authorize('update', $plan);
        $data = $this->validateCharacteristic($request);

        $plan->characteristics()->create($data + [
            'company_id' => $plan->company_id,
            'sequence' => $data['sequence'] ?? ((int) $plan->characteristics()->max('sequence') + 10),
        ]);

        return back()->with('success', 'Check added.');
    }

    public function updateCharacteristic(Request $request, string $uuid, string $characteristicUuid): RedirectResponse
    {
        $plan = $this->find($uuid);
        $this->authorize('update', $plan);
        $plan->characteristics()->where('uuid', $characteristicUuid)->firstOrFail()->update($this->validateCharacteristic($request));

        return back()->with('success', 'Check saved.');
    }

    public function destroyCharacteristic(Request $request, string $uuid, string $characteristicUuid): RedirectResponse
    {
        $plan = $this->find($uuid);
        $this->authorize('update', $plan);
        $plan->characteristics()->where('uuid', $characteristicUuid)->firstOrFail()->delete();

        return back()->with('success', 'Check removed. Inspections already recorded keep it.');
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function validatePlan(Request $request, ?InspectionPlan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', Rule::unique('mfg_inspection_plans', 'code')
                ->where('company_id', $this->tenantId($request))->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:255'],
            'stage' => ['required', Rule::in(InspectionPlan::STAGES)],
            'stock_item_id' => ['nullable', 'required_if:stage,INCOMING', 'integer', $this->tenantExists('fin_stock_items')],
            'item_uuid' => ['nullable', 'required_unless:stage,INCOMING', 'string', $this->tenantExists('mfg_items', 'uuid', true)],
            'supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id', true)],
            'routing_operation_uuid' => ['nullable', 'string', $this->tenantExists('mfg_routing_operations', 'uuid')],
            'sample_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'every_qty' => ['nullable', 'numeric', 'gt:0'],
            'blocks_production' => ['boolean'],
            'requires_certificate' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $incoming = $data['stage'] === InspectionPlan::INCOMING;
        $item = ! $incoming && ! empty($data['item_uuid']) ? Item::query()->where('uuid', $data['item_uuid'])->firstOrFail() : null;
        $operation = $data['stage'] === InspectionPlan::IN_PROCESS && ! empty($data['routing_operation_uuid'])
            ? RoutingOperation::query()->where('uuid', $data['routing_operation_uuid'])->firstOrFail() : null;

        // An operation's plan must be for an operation of the product's own routing.
        if ($operation && $item && ! Routing::withoutGlobalScopes()->whereKey($operation->routing_id)->where('item_id', $item->id)->exists()) {
            abort(back()->with('error', 'That operation is not on a routing for this product.'));
        }

        return [
            'code' => strtoupper(trim($data['code'])), 'name' => $data['name'], 'stage' => $data['stage'],
            'stock_item_id' => $incoming ? $data['stock_item_id'] : null,
            'item_id' => $item?->id,
            'supplier_id' => $incoming ? ($data['supplier_id'] ?? null) : null,
            'routing_operation_id' => $operation?->id,
            'sample_size' => $data['sample_size'] ?? null,
            'every_qty' => $data['stage'] === InspectionPlan::IN_PROCESS ? ($data['every_qty'] ?? null) : null,
            'blocks_production' => (bool) ($data['blocks_production'] ?? true),
            'requires_certificate' => (bool) ($data['requires_certificate'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function validateCharacteristic(Request $request): array
    {
        return $request->validate([
            'sequence' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([InspectionCharacteristic::MEASURED, InspectionCharacteristic::ATTRIBUTE])],
            'uom' => ['nullable', 'string', 'max:24'],
            'target' => ['nullable', 'numeric'],
            'lower_limit' => ['nullable', 'numeric'],
            'upper_limit' => ['nullable', 'numeric', Rule::when(fn ($i) => filled($i->lower_limit), 'gte:lower_limit')],
            'is_critical' => ['boolean'],
            'method' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function tenantId(Request $request): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
    }

    private function find(string $uuid): InspectionPlan
    {
        return InspectionPlan::query()->with(['item.stockItem:id,code,name', 'stockItem:id,code,name', 'routingOperation:id,uuid,sequence,name'])
            ->where('uuid', $uuid)->firstOrFail();
    }

    private function summarise(InspectionPlan $p): array
    {
        $subject = $p->stage === InspectionPlan::INCOMING ? $p->stockItem : $p->item?->stockItem;

        return [
            'id' => $p->uuid, 'code' => $p->code, 'name' => $p->name, 'stage' => $p->stage,
            'subject_code' => $subject?->code, 'subject_name' => $subject?->name,
            'operation' => $p->routingOperation ? "{$p->routingOperation->sequence} {$p->routingOperation->name}" : null,
            'supplier_id' => $p->supplier_id,
            'sample_size' => $p->sample_size, 'every_qty' => $p->every_qty !== null ? (float) $p->every_qty : null,
            'blocks_production' => (bool) $p->blocks_production, 'requires_certificate' => (bool) $p->requires_certificate,
            'is_active' => (bool) $p->is_active,
            'characteristics_count' => (int) ($p->characteristics_count ?? $p->characteristics()->count()),
        ];
    }

    /** The choices a plan form offers: materials, products, suppliers, and each product's operations. */
    private function options(): array
    {
        return [
            'materials' => FinStockItem::query()->where('is_active', true)->orderBy('code')->limit(2000)->get(['id', 'code', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values(),
            'products' => Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)
                ->with('stockItem:id,code,name')->orderBy('id')->get()
                ->map(fn (Item $i) => ['id' => $i->uuid, 'code' => $i->stockItem?->code, 'name' => $i->stockItem?->name])->values(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('company_name')->limit(2000)->get(['id', 'company_name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->company_name])->values(),
            // Operations of approved and draft routings, keyed to their product so the form can narrow.
            'operations' => RoutingOperation::query()
                ->join('mfg_routings as r', 'r.id', '=', 'mfg_routing_operations.routing_id')
                ->join('mfg_items as i', 'i.id', '=', 'r.item_id')
                ->whereIn('r.status', [Routing::DRAFT, Routing::APPROVED])
                ->orderBy('r.revision')->orderBy('mfg_routing_operations.sequence')
                ->get(['mfg_routing_operations.uuid', 'mfg_routing_operations.sequence', 'mfg_routing_operations.name', 'r.revision', 'i.uuid as item_uuid'])
                ->map(fn ($o) => ['id' => $o->uuid, 'item_id' => $o->item_uuid,
                    'name' => "Rev {$o->revision} · {$o->sequence} {$o->name}"])->values(),
        ];
    }
}
