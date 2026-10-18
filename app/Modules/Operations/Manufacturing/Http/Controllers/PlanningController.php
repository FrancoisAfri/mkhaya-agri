<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Forecast;
use App\Modules\Operations\Manufacturing\Models\PlanItem;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\PlanRecommendation;
use App\Modules\Operations\Manufacturing\Models\PlanRun;
use App\Modules\Operations\Manufacturing\Services\DemandPlanning;
use App\Modules\Operations\Manufacturing\Services\MaterialPlanner;
use App\Modules\Operations\Manufacturing\Services\PlanApproval;
use App\Modules\Operations\Manufacturing\Services\SupplierInsight;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demand planning, MRP, supplier performance and planning parameters (MFG-004/005/015).
 */
class PlanningController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly DemandPlanning $demand,
        private readonly MaterialPlanner $planner,
        private readonly PlanApproval $approval,
        private readonly SupplierInsight $suppliers,
    ) {}

    // ── Demand ───────────────────────────────────────────────────────────────────────────────

    public function demand(Request $request): Response
    {
        $this->authorize('viewAny', PlanRun::class);
        $rule = $request->validate(['rule' => ['nullable', Rule::in(DemandPlanning::RULES)]])['rule'] ?? 'DUE_DATE';
        $canManage = $request->user()->can('manage', PlanRun::class);

        return Inertia::render('Modules/Manufacturing/Planning/Demand', [
            'products' => $this->demand->products(6),
            'status' => $this->demand->statusSummary(),
            'orders' => $this->demand->prioritised($rule),
            'capacity' => $this->demand->capacity(6),
            'rule' => $rule, 'rules' => DemandPlanning::RULES,
            'forecasts' => Forecast::query()->with(['stockItem:id,code,name', 'factory:id,code'])->where('period_start', '>=', now()->startOfMonth()->subMonths(1))
                ->orderBy('period_start')->get()->map(fn (Forecast $f) => [
                    'id' => $f->uuid, 'item' => trim(($f->stockItem?->code ?? '').' — '.($f->stockItem?->name ?? '')), 'factory' => $f->factory?->code,
                    'period' => $f->period_start->format('Y-m'), 'qty' => (float) $f->qty, 'status' => $f->status, 'notes' => $f->notes,
                ])->values(),
            'options' => $canManage ? [
                'products' => \App\Modules\Operations\Manufacturing\Models\Item::query()->whereIn('kind', \App\Modules\Operations\Manufacturing\Models\Item::MANUFACTURED_KINDS)
                    ->where('is_active', true)->with('stockItem:id,code,name')->get()
                    ->map(fn ($i) => ['id' => $i->stock_item_id, 'name' => trim(($i->stockItem?->code ?? '').' — '.($i->stockItem?->name ?? ''))])->values(),
                'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                    ->map(fn ($f) => ['id' => $f->id, 'name' => "{$f->code} — {$f->name}"])->values(),
            ] : null,
            'can' => ['manage' => $canManage],
        ]);
    }

    public function storeForecast(Request $request): RedirectResponse
    {
        $this->authorize('manage', PlanRun::class);
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items')],
            'factory_id' => ['nullable', 'integer', $this->tenantExists('mfg_factories')],
            'period' => ['required', 'date_format:Y-m'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Forecast::create(['company_id' => $this->tenantId($request), 'stock_item_id' => $data['stock_item_id'], 'factory_id' => $data['factory_id'] ?? null,
            'period_start' => Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth()->toDateString(), 'qty' => $data['qty'],
            'status' => 'DRAFT', 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Forecast added as a draft. Approve it for the plan to use it.');
    }

    public function approveForecast(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', PlanRun::class);
        Forecast::query()->where('uuid', $uuid)->firstOrFail()->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id]);

        return back()->with('success', 'Forecast approved; the next plan run uses it.');
    }

    public function destroyForecast(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', PlanRun::class);
        Forecast::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', 'Forecast removed.');
    }

    // ── MRP ──────────────────────────────────────────────────────────────────────────────────

    public function mrp(Request $request): Response
    {
        $this->authorize('mrp', PlanRun::class);
        $uuid = $request->validate(['run' => ['nullable', 'string']])['run'] ?? null;
        $run = $uuid ? PlanRun::query()->where('uuid', $uuid)->firstOrFail() : PlanRun::query()->where('status', 'OPEN')->latest('id')->first();
        $user = $request->user();

        $items = $run ? $run->items()->with('stockItem:id,code,name')->get() : collect();
        $recs = $run ? $run->recommendations()->with('stockItem:id,code,name')->get() : collect();
        $supplierNames = DB::table('suppliers')->whereIn('id', $recs->pluck('supplier_id')->filter()->unique())->pluck('company_name', 'id');

        return Inertia::render('Modules/Manufacturing/Planning/Mrp', [
            'run' => $run ? ['id' => $run->uuid, 'number' => $run->number, 'starts_on' => $run->starts_on?->toDateString(), 'horizon_weeks' => $run->horizon_weeks,
                'status' => $run->status, 'summary' => $run->summary, 'exceptions' => $run->exceptions ?? [], 'run_at' => $run->created_at?->toIso8601String()] : null,
            'runs' => PlanRun::query()->latest('id')->limit(10)->get(['uuid', 'number', 'status', 'created_at'])
                ->map(fn ($r) => ['id' => $r->uuid, 'number' => $r->number, 'status' => $r->status, 'at' => $r->created_at?->toIso8601String()])->values(),
            'items' => $items->map(fn (PlanItem $i) => [
                'id' => $i->id, 'code' => $i->stockItem?->code, 'name' => $i->stockItem?->name, 'level' => $i->level, 'make_or_buy' => $i->make_or_buy,
                'on_hand' => (float) $i->on_hand, 'safety_stock' => (float) $i->safety_stock, 'gross' => (float) $i->gross,
                'scheduled_receipts' => (float) $i->scheduled_receipts, 'net' => (float) $i->net, 'planned' => (float) $i->planned,
                'projected_end' => (float) $i->projected_end, 'shortage_on' => $i->shortage_on?->toDateString(), 'available_on' => $i->available_on?->toDateString(),
                'timeline' => $i->timeline, 'demand' => $i->demand, 'exceptions' => $i->exceptions ?? [],
            ])->values(),
            'recommendations' => $recs->map(fn (PlanRecommendation $r) => [
                'id' => $r->uuid, 'kind' => $r->kind, 'code' => $r->stockItem?->code, 'name' => $r->stockItem?->name,
                'qty' => (float) $r->qty, 'net_qty' => (float) $r->net_qty, 'need_by' => $r->need_by?->toDateString(), 'release_by' => $r->release_by?->toDateString(),
                'supplier' => $r->supplier_id ? ($supplierNames[$r->supplier_id] ?? null) : null, 'unit_price' => $r->unit_price !== null ? (float) $r->unit_price : null,
                'status' => $r->status, 'override_qty' => $r->override_qty !== null ? (float) $r->override_qty : null,
                'override_date' => $r->override_date?->toDateString(), 'decision_reason' => $r->decision_reason, 'converted_type' => $r->converted_type,
            ])->values(),
            'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($f) => ['id' => $f->uuid, 'name' => "{$f->code} — {$f->name}"])->values(),
            'can' => ['run' => $user->can('manage', PlanRun::class), 'decide' => $user->can('approve', PlanRun::class) && $run?->status === 'OPEN'],
        ]);
    }

    public function runMrp(Request $request): RedirectResponse
    {
        $this->authorize('manage', PlanRun::class);
        $weeks = (int) ($request->validate(['horizon_weeks' => ['nullable', 'integer', 'between:1,52']])['horizon_weeks'] ?? 12);

        try {
            $run = $this->planner->run($request->user(), $weeks, $this->tenantId($request));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.planning.mrp', ['run' => $run->uuid])
            ->with('success', "{$run->number}: ".($run->summary['purchase'] ?? 0).' to buy, '.($run->summary['produce'] ?? 0).' to make. Review before approving.');
    }

    public function approve(Request $request): RedirectResponse
    {
        $this->authorize('approve', PlanRun::class);
        $data = $request->validate([
            'recommendations' => ['required', 'array', 'min:1'], 'recommendations.*' => ['string'],
            'factory' => ['nullable', 'string'], 'warehouse_id' => ['nullable', 'integer', $this->tenantExists('fin_warehouses')],
        ]);
        $recs = PlanRecommendation::query()->whereIn('uuid', $data['recommendations'])->get();
        $factory = isset($data['factory']) ? Factory::query()->where('uuid', $data['factory'])->firstOrFail() : null;

        try {
            $made = $this->approval->approve($recs, $request->user(), $factory, isset($data['warehouse_id']) ? FinWarehouse::query()->find($data['warehouse_id']) : null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', trim(
            ($made['production_orders'] ? 'Draft production orders: '.implode(', ', $made['production_orders']).'. ' : '').
            ($made['purchase_requests'] ? 'Draft purchase requests for approval: '.implode(', ', $made['purchase_requests']).'.' : '')));
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('approve', PlanRun::class);
        $rec = PlanRecommendation::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['override', 'reject'])],
            'qty' => ['nullable', 'numeric', 'gt:0'], 'date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $data['action'] === 'reject'
                ? $this->approval->reject($rec, $data['reason'], $request->user())
                : $this->approval->override($rec, isset($data['qty']) ? (float) $data['qty'] : null, $data['date'] ?? null, $data['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['action'] === 'reject' ? 'Rejected.' : 'Changed; approve it to act on it.');
    }

    // ── Suppliers ────────────────────────────────────────────────────────────────────────────

    public function suppliers(Request $request): Response
    {
        $this->authorize('suppliers', PlanRun::class);
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'item' => ['nullable', 'integer']]);
        $from = Carbon::parse($filters['from'] ?? now()->subYear()->toDateString());
        $to = Carbon::parse($filters['to'] ?? now()->toDateString());
        $run = PlanRun::query()->where('status', 'OPEN')->latest('id')->first();
        $needDates = $run ? $run->items()->whereNotNull('shortage_on')->pluck('shortage_on', 'stock_item_id')->map(fn ($d) => Carbon::parse($d)->toDateString())->all() : [];
        $item = isset($filters['item']) ? FinStockItem::query()->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class)->find($filters['item']) : null;

        return Inertia::render('Modules/Manufacturing/Planning/Suppliers', [
            'scorecard' => $this->suppliers->scorecard($from, $to),
            'risks' => $this->suppliers->deliveryRisks($needDates),
            'item' => $item ? ['id' => $item->id, 'name' => "{$item->code} — {$item->name}", 'prices' => $this->suppliers->prices($item->id),
                'substitutes' => $this->suppliers->substitutes($item->id)] : null,
            'materials' => FinStockItem::query()->where('is_active', true)->orderBy('code')->limit(2000)->get(['id', 'code', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => "{$s->code} — {$s->name}"])->values(),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'item' => $item?->id],
        ]);
    }

    // ── Schedule (MFG-014) ───────────────────────────────────────────────────────────────────

    public function schedule(Request $request): Response
    {
        $this->authorize('schedule', PlanRun::class);
        $data = $request->validate(['rule' => ['nullable', Rule::in(DemandPlanning::RULES)], 'days' => ['nullable', 'integer', 'between:7,180']]);
        $rule = $data['rule'] ?? 'DUE_DATE';
        $scheduler = app(\App\Modules\Operations\Manufacturing\Services\Scheduler::class);

        return Inertia::render('Modules/Manufacturing/Planning/Schedule', [
            'schedule' => $scheduler->schedule($rule, (int) ($data['days'] ?? 42)),
            'adherence' => $scheduler->adherence(now()->subDays(30), now()),
            'last_run' => \App\Modules\Operations\Manufacturing\Models\ScheduleRun::query()->latest('id')->first()?->only(['rule', 'summary', 'created_at']),
            'rule' => $rule, 'rules' => DemandPlanning::RULES, 'days' => (int) ($data['days'] ?? 42),
            'can' => ['manage' => $request->user()->can('manage', PlanRun::class)],
        ]);
    }

    /** What a reschedule would do -- due dates, capacity, material -- without saving it. */
    public function previewSchedule(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('manage', PlanRun::class);
        [$rule, $days, $changes] = $this->scheduleInput($request);

        return response()->json(app(\App\Modules\Operations\Manufacturing\Services\Scheduler::class)->schedule($rule, $days, $changes));
    }

    public function applySchedule(Request $request): RedirectResponse
    {
        $this->authorize('manage', PlanRun::class);
        [$rule, $days, $changes] = $this->scheduleInput($request);
        $result = app(\App\Modules\Operations\Manufacturing\Services\Scheduler::class)->schedule($rule, $days, $changes, true, $request->user());
        $late = collect($result['orders'])->where('late', true)->count();

        return back()->with('success', 'Schedule saved'.($late ? "; {$late} order(s) finish after their due date." : '; every order is on time.'));
    }

    private function scheduleInput(Request $request): array
    {
        $data = $request->validate([
            'rule' => ['nullable', Rule::in(DemandPlanning::RULES)], 'days' => ['nullable', 'integer', 'between:7,180'],
            'changes' => ['nullable', 'array'], 'changes.*.earliest_start' => ['nullable', 'date'], 'changes.*.priority' => ['nullable', 'integer', 'between:1,5'],
        ]);

        return [$data['rule'] ?? 'DUE_DATE', (int) ($data['days'] ?? 42), $data['changes'] ?? []];
    }

    // ── Parameters ───────────────────────────────────────────────────────────────────────────

    public function parameters(Request $request): Response
    {
        $this->authorize('parameters', PlanRun::class);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:120']])['search'] ?? null;
        $params = PlanningParam::query()->get()->keyBy('stock_item_id');
        $wms = \Illuminate\Support\Facades\Schema::hasTable('wms_item_profiles') ? DB::table('wms_item_profiles')->get()->keyBy('stock_item_id') : collect();
        $suppliers = DB::table('suppliers')->whereNull('deleted_at')->where('is_active', true)->orderBy('company_name')->limit(2000)->get(['id', 'company_name']);

        return Inertia::render('Modules/Manufacturing/Planning/Parameters', [
            'items' => FinStockItem::query()->where('is_active', true)
                ->when($search, fn ($q, $t) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$t}%")->orWhere('name', 'ilike', "%{$t}%")))
                ->orderBy('code')->paginate(50)->withQueryString()
                ->through(function ($s) use ($params, $wms) {
                    $p = $params[$s->id] ?? null;
                    $w = $wms[$s->id] ?? null;

                    return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name,
                        'make_or_buy' => $p?->make_or_buy, 'lead_time_days' => $p?->lead_time_days, 'safety_stock' => $p?->safety_stock !== null ? (float) $p->safety_stock : null,
                        'min_order_qty' => $p?->min_order_qty !== null ? (float) $p->min_order_qty : null, 'order_multiple' => $p?->order_multiple !== null ? (float) $p->order_multiple : null,
                        'preferred_supplier_id' => $p?->preferred_supplier_id,
                        'warehouse_lead_time' => $w->lead_time_days ?? null, 'warehouse_safety_stock' => isset($w->safety_stock) ? (float) $w->safety_stock : null];
                }),
            'suppliers' => $suppliers->map(fn ($s) => ['id' => $s->id, 'name' => $s->company_name])->values(),
            'filters' => ['search' => $search],
            'can' => ['manage' => $request->user()->can('manage', PlanRun::class)],
        ]);
    }

    public function saveParameters(Request $request, int $stockItemId): RedirectResponse
    {
        $this->authorize('manage', PlanRun::class);
        $item = FinStockItem::query()->findOrFail($stockItemId);
        $data = $request->validate([
            'make_or_buy' => ['nullable', Rule::in(['MAKE', 'BUY'])],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'min_order_qty' => ['nullable', 'numeric', 'min:0'],
            'order_multiple' => ['nullable', 'numeric', 'gt:0'],
            'preferred_supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id', true)],
        ]);
        PlanningParam::query()->updateOrCreate(['stock_item_id' => $item->id], $data + ['company_id' => $this->tenantId($request)]);

        return back()->with('success', "Planning parameters saved for {$item->code}.");
    }

    private function tenantId(Request $request): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
    }
}
