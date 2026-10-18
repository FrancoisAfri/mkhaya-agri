<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use App\Modules\Operations\Manufacturing\Models\StandardCostLine;
use App\Modules\Operations\Manufacturing\Services\CostAnalysis;
use App\Modules\Operations\Manufacturing\Services\Profitability;
use App\Modules\Operations\Manufacturing\Services\StandardCosting;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Costing (MFG-011): standard costs, order variances and profitability.
 */
class CostingController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly StandardCosting $standards,
        private readonly CostAnalysis $analysis,
        private readonly Profitability $profitability,
    ) {}

    public function standards(Request $request): Response
    {
        $this->authorize('viewAny', StandardCost::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in([StandardCost::DRAFT, StandardCost::APPROVED, StandardCost::SUPERSEDED])],
            'search' => ['nullable', 'string', 'max:120']]);
        $canCreate = $request->user()->can('create', StandardCost::class);

        return Inertia::render('Modules/Manufacturing/Costing/Standards', [
            'standards' => StandardCost::query()->with('item.stockItem:id,code,name')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['search'] ?? null, fn ($q, $t) => $q->whereHas('item.stockItem', fn ($s) => $s->where('code', 'ilike', "%{$t}%")->orWhere('name', 'ilike', "%{$t}%")))
                ->orderByRaw("CASE status WHEN 'DRAFT' THEN 0 WHEN 'APPROVED' THEN 1 ELSE 2 END")->orderByDesc('id')
                ->paginate(25)->withQueryString()->through(fn (StandardCost $s) => $this->summarise($s)),
            'filters' => $filters,
            'products' => $canCreate ? Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)->with('stockItem:id,code,name')
                ->orderBy('id')->get()->map(fn (Item $i) => ['id' => $i->uuid, 'code' => $i->stockItem?->code, 'name' => $i->stockItem?->name])->values() : [],
            'currency' => config('finance.base_currency', 'ZAR'),
            'can' => ['create' => $canCreate],
        ]);
    }

    public function calculate(Request $request): RedirectResponse
    {
        $this->authorize('create', StandardCost::class);
        $data = $request->validate([
            'item_uuid' => ['required', 'string', $this->tenantExists('mfg_items', 'uuid', true)],
            'costing_qty' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $sc = $this->standards->calculate(Item::query()->where('uuid', $data['item_uuid'])->firstOrFail(), (float) $data['costing_qty'], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.costing.standards.show', $sc->uuid)->with('success', "Standard cost v{$sc->version} calculated as a draft.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $sc = StandardCost::query()->with(['item.stockItem:id,code,name', 'lines', 'bom:id,uuid,revision', 'routing:id,uuid,revision'])->where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $sc);

        return Inertia::render('Modules/Manufacturing/Costing/StandardShow', [
            'standard' => $this->summarise($sc) + [
                'bom' => $sc->bom ? ['id' => $sc->bom->uuid, 'revision' => $sc->bom->revision] : null,
                'routing' => $sc->routing ? ['id' => $sc->routing->uuid, 'revision' => $sc->routing->revision] : null,
                'expected_output' => (float) $sc->expected_output, 'labour_minutes' => (float) $sc->labour_minutes,
                'machine_minutes' => (float) $sc->machine_minutes, 'overhead_percent' => $sc->overhead_percent !== null ? (float) $sc->overhead_percent : null,
                'warnings' => $sc->warnings ?? [], 'approved_at' => $sc->approved_at?->toIso8601String(),
                'lines' => $sc->lines->map(fn (StandardCostLine $l) => [
                    'id' => $l->id, 'category' => $l->category, 'reference' => $l->reference, 'quantity' => (float) $l->quantity,
                    'uom' => $l->uom, 'rate' => (float) $l->rate, 'amount' => (float) $l->amount, 'basis' => $l->basis,
                ])->values(),
            ],
            'currency' => config('finance.base_currency', 'ZAR'),
            'can' => ['approve' => $request->user()->can('approve', $sc), 'recalculate' => $request->user()->can('create', StandardCost::class)],
        ]);
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $sc = StandardCost::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('approve', $sc);

        try {
            $this->standards->approve($sc, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Standard cost v{$sc->version} approved; new orders are measured against it.");
    }

    /** Orders made in a period, with their cost status and variance against standard. */
    public function variances(Request $request): Response
    {
        $this->authorize('variances', StandardCost::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'factory' => ['nullable', 'string'], 'status' => ['nullable', Rule::in([CostAnalysis::PROVISIONAL, CostAnalysis::FINAL])],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $factory = isset($filters['factory']) ? Factory::query()->where('uuid', $filters['factory'])->first() : null;

        $rows = PO::query()->with(['item.stockItem:id,code,name', 'standardCost', 'variances'])
            ->where('qty_completed', '>', 0)
            ->whereIn('status', [PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::COMPLETED, PO::CLOSED])
            ->where(fn ($q) => $q->whereBetween('completed_at', [$from, \Illuminate\Support\Carbon::parse($to)->endOfDay()])
                ->orWhere(fn ($w) => $w->whereNull('completed_at')->whereBetween('started_at', [$from, \Illuminate\Support\Carbon::parse($to)->endOfDay()])))
            ->when($factory, fn ($q) => $q->where('factory_id', $factory->id))
            ->orderByDesc('completed_at')->limit(500)->get()
            ->map(function (PO $o) {
                $a = $this->analysis->analyse($o);
                $by = collect($a['variances'])->keyBy('kind');

                return [
                    'id' => $o->uuid, 'number' => $o->number, 'status' => $o->status,
                    'product' => trim(($o->item?->stockItem?->code ?? '').' — '.($o->item?->stockItem?->name ?? '')),
                    'good' => (float) $o->qty_completed, 'actual' => $a['actual']['total'], 'standard' => $a['standard']['for_output'] ?? null,
                    'total' => $by['TOTAL']['amount'] ?? null, 'material' => round(($by['MATERIAL_PRICE']['amount'] ?? 0) + ($by['MATERIAL_USAGE']['amount'] ?? 0), 2),
                    'labour' => round(($by['LABOUR_RATE']['amount'] ?? 0) + ($by['LABOUR_EFFICIENCY']['amount'] ?? 0), 2),
                    'machine' => $by['MACHINE']['amount'] ?? 0, 'scrap_rework' => $by['SCRAP_REWORK']['amount'] ?? 0,
                    'cost_status' => $a['status'], 'frozen' => $o->variances->isNotEmpty(),
                ];
            })
            ->when($filters['status'] ?? null, fn ($c, $s) => $c->where('cost_status', $s))
            ->values();

        return Inertia::render('Modules/Manufacturing/Costing/Variances', [
            'rows' => $rows,
            'totals' => ['actual' => round($rows->sum('actual'), 2), 'standard' => round($rows->sum('standard'), 2), 'total' => round($rows->sum('total'), 2),
                'scrap_rework' => round($rows->sum('scrap_rework'), 2)],
            'filters' => ['from' => $from, 'to' => $to, 'factory' => $filters['factory'] ?? null, 'status' => $filters['status'] ?? null],
            'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($f) => ['id' => $f->uuid, 'name' => "{$f->code} — {$f->name}"])->values(),
            'currency' => config('finance.base_currency', 'ZAR'),
        ]);
    }

    public function profitability(Request $request): Response
    {
        $this->authorize('profitability', StandardCost::class);
        $filters = $request->validate([
            'dimension' => ['nullable', Rule::in(['product', 'category', 'order', 'factory', 'line', 'customer'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'factory' => ['nullable', 'string'],
        ]);
        $dimension = $filters['dimension'] ?? 'product';
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $factory = isset($filters['factory']) ? Factory::query()->where('uuid', $filters['factory'])->first() : null;

        return Inertia::render('Modules/Manufacturing/Costing/Profitability', [
            'report' => $this->profitability->report($dimension, $from, $to, $factory?->id),
            'filters' => ['dimension' => $dimension, 'from' => $from, 'to' => $to, 'factory' => $filters['factory'] ?? null],
            'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($f) => ['id' => $f->uuid, 'name' => "{$f->code} — {$f->name}"])->values(),
        ]);
    }

    private function summarise(StandardCost $s): array
    {
        return [
            'id' => $s->uuid, 'version' => (int) $s->version, 'status' => $s->status,
            'product' => trim(($s->item?->stockItem?->code ?? '').' — '.($s->item?->stockItem?->name ?? '')),
            'costing_qty' => (float) $s->costing_qty, 'unit_cost' => (float) $s->unit_cost, 'total_cost' => (float) $s->total_cost,
            'material' => (float) $s->material_cost, 'labour' => (float) $s->labour_cost, 'machine' => (float) $s->machine_cost,
            'overhead' => (float) $s->overhead_cost, 'subcontract' => (float) $s->subcontract_cost,
            'warnings_count' => count($s->warnings ?? []), 'effective_from' => $s->effective_from?->toDateString(),
        ];
    }
}
