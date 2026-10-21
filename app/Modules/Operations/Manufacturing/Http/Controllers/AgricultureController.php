<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Forecast;
use App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Record;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Restriction;
use App\Modules\Operations\Manufacturing\Models\Agriculture\TypeVersion;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Unit;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Forecasts;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Metrics;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Processing;
use App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionCycles;
use App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agricultural production in Manufacturing & Production: the overview, cycles with their records
 * and metrics, the units they run in, the production types they are configured by, processing of
 * farm produce, and the offline sync endpoint field devices post to.
 *
 * Every page is confined to the farms the viewer may reach; another organisation's records are
 * not found at all.
 */
class AgricultureController extends Controller
{
    public function __construct(
        private readonly ProductionCycles $cycles,
        private readonly ProductionTypes $types,
        private readonly Metrics $metrics,
        private readonly FarmAccess $access,
    ) {}

    public function overview(Request $request): Response
    {
        $this->authorize('viewAny', Cycle::class);
        $this->ensureTypes($request);
        $active = $this->scoped(Cycle::query(), $request)->where('status', Cycle::ACTIVE)->with(['type', 'unit', 'farm'])->get();

        return Inertia::render('Modules/Manufacturing/Agriculture/Overview', [
            'byCategory' => $active->groupBy(fn ($c) => $c->type->category)->map(fn ($g, $cat) => [
                'category' => $cat, 'cycles' => $g->count(), 'count' => (int) $g->sum('current_count'),
                'count_unit' => $g->first()->type->count_unit, 'area' => round((float) $g->sum('area'), 2),
            ])->values(),
            'cycles' => $active->sortBy('code')->take(50)->map(fn (Cycle $c) => $this->cycleRow($c))->values(),
            'units' => $this->scoped(Unit::query(), $request)->count(),
            'held' => Restriction::query()->whereNull('lifted_at')->where(fn ($q) => $q->whereNull('until_on')->orWhereDate('until_on', '>=', today()))->count(),
            'can' => $this->can($request),
        ]);
    }

    public function cycles(Request $request): Response
    {
        $this->authorize('viewAny', Cycle::class);
        $this->ensureTypes($request);
        $f = $request->validate(['status' => ['nullable', Rule::in(['active', 'planned', 'closed', 'all'])], 'category' => ['nullable', Rule::in(ProductionType::CATEGORIES)]]);
        $status = $f['status'] ?? 'active';
        $cycles = $this->scoped(Cycle::query(), $request)->with(['type', 'unit', 'farm'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->whereHas('type', fn ($t) => $t->where('category', $c)))
            ->orderByDesc('started_on')->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('Modules/Manufacturing/Agriculture/Cycles', [
            'cycles' => $cycles->through(fn (Cycle $c) => $this->cycleRow($c)),
            'filters' => ['status' => $status, 'category' => $f['category'] ?? null],
            'categories' => ProductionType::CATEGORIES,
            'options' => $this->options($request),
            'can' => $this->can($request),
        ]);
    }

    public function show(Request $request, string $cycle): Response
    {
        $this->authorize('viewAny', Cycle::class);
        $c = $this->findCycle($request, $cycle)->load(['type', 'unit', 'farm', 'stageEvents', 'unitHistory', 'restrictions']);
        $records = $c->records()->with([])->orderByDesc('recorded_at')->orderByDesc('id')->limit(200)->get();
        $def = $c->definition();
        $from = Carbon::parse(max($c->started_on?->toDateString() ?? today()->subDays(7)->toDateString(), today()->subDays(13)->toDateString()));

        return Inertia::render('Modules/Manufacturing/Agriculture/CycleShow', [
            'cycle' => $this->cycleRow($c) + [
                'opening_count' => $c->opening_count, 'expected_end_on' => $c->expected_end_on?->toDateString(), 'ended_on' => $c->ended_on?->toDateString(),
                'breed' => $c->breed, 'source_ref' => $c->source_ref, 'custom' => $c->custom, 'notes' => $c->notes, 'type_version' => $c->type_version,
                'livestock_group' => $c->livestockGroup?->code, 'crop_plan' => $c->cropPlan?->code, 'production_order_id' => $c->production_order_id,
                'cost_to_date' => $c->cost_to_date, 'output_quantity' => $c->output_quantity,
            ],
            'definition' => ['stages' => $def['stages'] ?? [], 'custom_fields' => $def['custom_fields'] ?? [], 'measurements' => $def['measurements'] ?? [],
                'count_unit' => $def['count_unit'] ?? null, 'output_unit' => $def['output_unit'] ?? null, 'category' => $def['category'] ?? null],
            'metrics' => $c->status === Cycle::CLOSED && $c->closing_metrics ? $c->closing_metrics : $this->metrics->compute($c),
            'records' => $records->map(fn (Record $r) => ['id' => $r->uuid, 'kind' => $r->kind, 'recorded_at' => $r->recorded_at?->toIso8601String(),
                'quantity' => $r->quantity, 'unit' => $r->unit, 'count_delta' => $r->count_delta, 'cost' => $r->cost, 'grade' => $r->grade, 'reason' => $r->reason,
                'details' => $r->details, 'source' => $r->source, 'link_type' => $r->link_type])->values(),
            'stages' => $c->stageEvents->map(fn ($e) => ['from' => $e->from_stage, 'to' => $e->to_stage, 'at' => $e->at?->toIso8601String(), 'note' => $e->note])->values(),
            'units' => $c->unitHistory->map(fn ($u) => ['unit' => Unit::query()->whereKey($u->unit_id)->value('name'), 'from' => $u->from_at?->toIso8601String(),
                'to' => $u->to_at?->toIso8601String(), 'count' => $u->count, 'reason' => $u->reason])->values(),
            'blocks' => $c->status === Cycle::CLOSED ? [] : $this->cycles->blocks($c),
            'restrictions' => $c->restrictions->map(fn (Restriction $r) => ['id' => $r->uuid, 'kind' => $r->kind, 'reason' => $r->reason, 'starts_on' => $r->starts_on?->toDateString(),
                'until_on' => $r->until_on?->toDateString(), 'active' => $r->isActive(), 'lift_note' => $r->lift_note])->values(),
            'reconcile' => $c->unit_id ? $this->metrics->reconcile($c, $from, today()) : [],
            'mortality' => $c->opening_count ? $this->metrics->dailyMortality($c, 14) : [],
            'forecasts' => Forecast::query()->where('cycle_id', $c->id)->latest('made_at')->limit(10)->get()->map(fn (Forecast $f) => ['metric' => $f->metric,
                'target_on' => $f->target_on?->toDateString(), 'predicted' => $f->predicted, 'lower' => $f->lower, 'upper' => $f->upper, 'unit' => $f->unit,
                'model' => "{$f->model} {$f->model_version}", 'actual' => $f->actual, 'abs_pct_error' => $f->abs_pct_error])->values(),
            'moveTo' => $this->scoped(Unit::query(), $request)->where('farm_id', $c->farm_id)->whereKeyNot($c->unit_id ?? 0)->whereNotIn('status', ['retired'])
                ->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($u) => ['value' => $u->uuid, 'title' => "{$u->code} — {$u->name}"]),
            'kinds' => Record::KINDS,
            'can' => $this->can($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate([
            'production_type_id' => ['required', 'uuid'], 'unit_id' => ['nullable', 'uuid'], 'farm_id' => ['nullable', 'uuid'], 'code' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:160'], 'started_on' => ['nullable', 'date', 'before_or_equal:today'], 'status' => ['nullable', Rule::in(['active', 'planned'])],
            'opening_count' => ['nullable', 'integer', 'min:1'], 'area' => ['nullable', 'numeric', 'min:0'], 'breed' => ['nullable', 'string', 'max:80'],
            'source_ref' => ['nullable', 'string', 'max:80'], 'crop_plan_id' => ['nullable', 'uuid'], 'custom' => ['nullable', 'array'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $type = ProductionType::query()->where('uuid', $d['production_type_id'])->firstOrFail();
        $unit = ! empty($d['unit_id']) ? $this->scoped(Unit::query(), $request)->where('uuid', $d['unit_id'])->firstOrFail() : null;
        $farm = ! empty($d['farm_id']) ? $this->reachableFarm($request, $d['farm_id']) : null;
        $plan = ! empty($d['crop_plan_id']) ? CropPlan::query()->where('uuid', $d['crop_plan_id'])->firstOrFail() : null;
        $c = $this->cycles->start($type, $unit, ['farm_id' => $farm?->id, 'crop_plan_id' => $plan?->id] + collect($d)->except(['production_type_id', 'unit_id', 'farm_id', 'crop_plan_id'])->all(), $request->user());

        return redirect('/manufacturing/agriculture/cycles/'.$c->uuid)->with('success', "Cycle {$c->code} started.");
    }

    public function record(Request $request, string $cycle): RedirectResponse
    {
        $this->authorize('record', Cycle::class);
        $c = $this->findCycle($request, $cycle);
        $d = $this->recordRules($request);
        $data = $this->recordData($request, $d);
        // A time typed on a form is the farm's wall clock, not the server's.
        if (! empty($data['recorded_at']) && ! preg_match('/(Z|[+-]\d\d:?\d\d)$/', (string) $data['recorded_at'])) {
            $tz = Farm::withoutGlobalScopes()->whereKey($c->farm_id)->value('timezone') ?: config('app.timezone');
            $data['recorded_at'] = Carbon::parse($data['recorded_at'], $tz);
        }
        $this->cycles->record($c, $d['kind'], $data, $request->user());

        return back()->with('success', ucfirst($d['kind']).' recorded.');
    }

    public function advance(Request $request, string $cycle): RedirectResponse
    {
        $this->authorize('record', Cycle::class);
        $d = $request->validate(['stage' => ['required', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:500']]);
        $c = $this->cycles->advance($this->findCycle($request, $cycle), $d['stage'], $request->user(), $d['note'] ?? null);

        return back()->with('success', "Now at {$c->stage}.");
    }

    public function move(Request $request, string $cycle): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['unit_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:120']]);
        $to = $this->scoped(Unit::query(), $request)->where('uuid', $d['unit_id'])->firstOrFail();
        $this->cycles->move($this->findCycle($request, $cycle), $to, $request->user(), $d['reason'] ?? null);

        return back()->with('success', "Moved to {$to->name}.");
    }

    public function close(Request $request, string $cycle): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $c = $this->cycles->close($this->findCycle($request, $cycle), $request->user(), $d['notes'] ?? null);

        return back()->with('success', "Cycle {$c->code} closed.");
    }

    public function forecast(Request $request, string $cycle, Forecasts $forecasts): RedirectResponse
    {
        $this->authorize('record', Cycle::class);
        $c = $this->findCycle($request, $cycle);
        $on = $c->expected_end_on ?? today()->addWeek();
        $f = $forecasts->weight($c, Carbon::parse($on));

        return back()->with($f ? 'success' : 'error', $f ? sprintf('Forecast %.2f kg on %s (%.2f–%.2f).', $f->predicted, $f->target_on->toDateString(), $f->lower, $f->upper)
            : 'At least three weighings are needed for a forecast.');
    }

    public function lift(Request $request, string $restriction): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $r = Restriction::query()->where('uuid', $restriction)->firstOrFail();
        $this->findCycle($request, Cycle::query()->whereKey($r->cycle_id)->value('uuid'));
        $this->cycles->lift($r, $request->user(), $d['note']);

        return back()->with('success', 'Hold lifted.');
    }

    /**
     * Records captured offline, sent as a batch when the device is back in coverage. Each is
     * applied on its own: a duplicate (same client id) is counted, an invalid one is reported back
     * by its client id, and neither stops the rest.
     */
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('record', Cycle::class);
        $request->validate(['records' => ['required', 'array', 'max:500'], 'records.*.client_id' => ['required', 'string', 'max:80'],
            'records.*.cycle_id' => ['required', 'uuid'], 'records.*.kind' => ['required', Rule::in(Record::KINDS)]]);
        $created = 0;
        $dupes = 0;
        $errors = [];
        foreach ($request->input('records') as $row) {
            if (Record::query()->where('client_id', $row['client_id'])->exists()) {
                $dupes++;

                continue;
            }
            try {
                $c = $this->findCycle($request, $row['cycle_id']);
                $this->cycles->record($c, $row['kind'], collect($row)->only(['client_id', 'recorded_at', 'quantity', 'unit', 'count', 'reason', 'grade', 'cost',
                    'sample_size', 'uniformity_pct', 'product_name', 'withdrawal_days', 'dose', 'note'])->all(), $request->user(), 'offline');
                $created++;
            } catch (ValidationException $e) {
                $errors[] = ['client_id' => $row['client_id'], 'errors' => $e->errors()];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $errors[] = ['client_id' => $row['client_id'], 'errors' => ['cycle_id' => ['Cycle not found.']]];
            }
        }

        return response()->json(['created' => $created, 'duplicates' => $dupes, 'errors' => $errors, 'at' => now()->toIso8601String()]);
    }

    // ── Units ─────────────────────────────────────────────────────────────────────────────────

    public function units(Request $request): Response
    {
        $this->authorize('viewAny', Cycle::class);
        $this->ensureTypes($request);
        $active = Cycle::query()->where('status', Cycle::ACTIVE)->pluck('code', 'unit_id');

        return Inertia::render('Modules/Manufacturing/Agriculture/Units', [
            'units' => $this->scoped(Unit::query(), $request)->with(['farm:id,name,code', 'type:id,name'])->withCount('devices')->orderBy('farm_id')->orderBy('code')->get()
                ->map(fn (Unit $u) => ['id' => $u->uuid, 'code' => $u->code, 'name' => $u->name, 'kind' => $u->kind, 'farm' => $u->farm?->name, 'type' => $u->type?->name,
                    'capacity' => $u->capacity, 'capacity_unit' => $u->capacity_unit, 'area' => $u->area, 'area_unit' => $u->area_unit, 'status' => $u->status,
                    'devices' => $u->devices_count, 'cycle' => $active[$u->id] ?? null, 'zone' => $u->biosecurity_zone])->values(),
            'kinds' => Unit::KINDS,
            'options' => $this->options($request),
            'can' => $this->can($request),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['farm_id' => ['required', 'uuid'], 'field_id' => ['nullable', 'uuid'], 'production_type_id' => ['nullable', 'uuid'],
            'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:120'], 'kind' => ['required', Rule::in(Unit::KINDS)],
            'capacity' => ['nullable', 'numeric', 'min:0'], 'capacity_unit' => ['nullable', 'string', 'max:20'], 'area' => ['nullable', 'numeric', 'min:0'],
            'area_unit' => ['nullable', Rule::in(['m2', 'ha'])], 'biosecurity_zone' => ['nullable', 'string', 'max:40']]);
        $farm = $this->reachableFarm($request, $d['farm_id']);
        if (Unit::query()->where('farm_id', $farm->id)->whereRaw('lower(code) = ?', [mb_strtolower($d['code'])])->exists()) {
            throw ValidationException::withMessages(['code' => "{$farm->name} already has a unit {$d['code']}."]);
        }
        Unit::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id,
            'field_id' => ! empty($d['field_id']) ? FarmField::query()->where('farm_id', $farm->id)->where('uuid', $d['field_id'])->value('id') : null,
            'production_type_id' => ! empty($d['production_type_id']) ? ProductionType::query()->where('uuid', $d['production_type_id'])->value('id') : null,
        ] + collect($d)->only(['code', 'name', 'kind', 'capacity', 'capacity_unit', 'area', 'area_unit', 'biosecurity_zone'])->all());

        return back()->with('success', "{$d['name']} added.");
    }

    // ── Production types ─────────────────────────────────────────────────────────────────────

    public function types(Request $request): Response
    {
        $this->authorize('manage', Cycle::class);
        $this->ensureTypes($request);
        $types = ProductionType::query()->with('parent:id,code,name')->orderBy('category')->orderBy('parent_id')->orderBy('code')->get();

        return Inertia::render('Modules/Manufacturing/Agriculture/Types', [
            'types' => $types->map(fn (ProductionType $t) => ['id' => $t->uuid, 'code' => $t->code, 'name' => $t->name, 'category' => $t->category,
                'parent' => $t->parent?->name, 'version' => $t->version, 'is_active' => $t->is_active, 'unit_kind' => $t->unit_kind,
                'definition' => $this->types->definition($t), 'own' => ['stages' => $t->stages ?? [], 'custom_fields' => $t->custom_fields ?? [], 'metrics' => $t->metrics ?? []],
                'versions' => TypeVersion::query()->where('production_type_id', $t->id)->orderByDesc('version')->get()
                    ->map(fn ($v) => ['version' => $v->version, 'note' => $v->change_note, 'at' => $v->created_at?->toDateString()])->values(),
                'cycles' => Cycle::query()->where('production_type_id', $t->id)->count()])->values(),
            'categories' => ProductionType::CATEGORIES,
            'variables' => ProductionTypes::VARIABLES,
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['parent_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/'], 'name' => ['required', 'string', 'max:120'],
            'stages' => ['nullable', 'array'], 'custom_fields' => ['nullable', 'array'], 'metrics' => ['nullable', 'array'], 'defaults' => ['nullable', 'array']]);
        $parent = ProductionType::query()->where('uuid', $d['parent_id'])->firstOrFail();
        if (ProductionType::query()->where('code', $d['code'])->exists()) {
            throw ValidationException::withMessages(['code' => "{$d['code']} is taken."]);
        }
        $t = $this->types->subtype($parent, collect($d)->except('parent_id')->all(), $request->user());

        return back()->with('success', "{$t->name} added as a subtype of {$parent->name}.");
    }

    public function reviseType(Request $request, string $type): RedirectResponse
    {
        $this->authorize('manage', Cycle::class);
        $d = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'stages' => ['nullable', 'array'], 'custom_fields' => ['nullable', 'array'],
            'metrics' => ['nullable', 'array'], 'is_active' => ['nullable', 'boolean'], 'change_note' => ['required', 'string', 'max:500']]);
        $t = ProductionType::query()->where('uuid', $type)->firstOrFail();
        $t = $this->types->revise($t, collect($d)->except('change_note')->filter(fn ($v) => $v !== null)->all(), $request->user(), $d['change_note']);

        return back()->with('success', "{$t->name} is now version {$t->version}; cycles already running keep the version they started on.");
    }

    // ── Processing ───────────────────────────────────────────────────────────────────────────

    public function processing(Request $request): Response
    {
        $this->authorize('process', Cycle::class);
        $farms = $this->access->farmIdsFor($request->user());
        $lots = ProduceLot::query()->whereIn('status', ['available', 'quarantined'])->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))
            ->with('farm:id,name,code')->orderByDesc('produced_on')->limit(200)->get();

        return Inertia::render('Modules/Manufacturing/Agriculture/Processing', [
            'lots' => $lots->map(fn (ProduceLot $l) => ['id' => $l->uuid, 'code' => $l->code, 'product' => $l->product, 'grade' => $l->grade, 'kind' => $l->kind,
                'remaining' => $l->remaining, 'unit' => $l->unit, 'status' => $l->status, 'farm' => $l->farm?->name, 'farm_id' => $l->farm?->uuid,
                'produced_on' => $l->produced_on?->toDateString(), 'stocked' => (bool) $l->stock_lot_id])->values(),
            'operations' => PackhouseOperation::query()->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))->latest('performed_on')->latest('id')->limit(50)->get()
                ->map(fn (PackhouseOperation $o) => ['reference' => $o->reference, 'kind' => $o->kind, 'performed_on' => $o->performed_on?->toDateString(),
                    'input' => $o->input_quantity, 'output' => $o->output_quantity, 'loss' => $o->loss_quantity, 'quality_passed' => $o->quality_passed,
                    'output_value' => $o->output_value, 'production_order_id' => $o->production_order_id])->values(),
            'batches' => $this->scoped(Cycle::query(), $request)->whereHas('type', fn ($q) => $q->where('category', 'processing'))->whereIn('status', [Cycle::ACTIVE, Cycle::PLANNED])
                ->get(['uuid', 'code', 'name'])->map(fn ($c) => ['value' => $c->uuid, 'title' => "{$c->code} — {$c->name}"]),
            'items' => FinStockItem::query()->where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name', 'uom'])
                ->map(fn ($i) => ['value' => $i->id, 'title' => "{$i->code} — {$i->name}", 'uom' => $i->uom]),
            'stores' => FarmStore::query()->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))->get(['id', 'uuid', 'name', 'farm_id'])
                ->map(fn ($s) => ['value' => $s->id, 'title' => $s->name, 'farm_id' => Farm::withoutGlobalScopes()->whereKey($s->farm_id)->value('uuid')]),
            'kinds' => PackhouseOperation::KINDS,
            'can' => ['process' => $request->user()->can('process', Cycle::class), 'release' => $request->user()->can(\App\Modules\Operations\Manufacturing\Support\RoleCatalog::DISPOSE)],
        ]);
    }

    public function runProcessing(Request $request, Processing $processing): RedirectResponse
    {
        $this->authorize('process', Cycle::class);
        $d = $request->validate(['farm_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(PackhouseOperation::KINDS)], 'batch_id' => ['nullable', 'uuid'],
            'inputs' => ['required', 'array', 'min:1'], 'inputs.*.lot_id' => ['required', 'uuid'], 'inputs.*.quantity' => ['required', 'numeric', 'gt:0'],
            'outputs' => ['required', 'array', 'min:1'], 'outputs.*.product' => ['required', 'string', 'max:120'], 'outputs.*.grade' => ['nullable', 'string', 'max:40'],
            'outputs.*.quantity' => ['required', 'numeric', 'gt:0'], 'outputs.*.unit' => ['required', 'string', 'max:12'], 'outputs.*.stock_item_id' => ['nullable', 'integer'],
            'outputs.*.store_id' => ['nullable', 'integer'], 'quality_checks' => ['nullable', 'array'], 'loss_reason' => ['nullable', 'string', 'max:255']]);
        $farm = $this->reachableFarm($request, $d['farm_id']);
        $inputs = collect($d['inputs'])->map(fn ($i) => ['lot' => ProduceLot::query()->where('farm_id', $farm->id)->where('uuid', $i['lot_id'])->firstOrFail(), 'quantity' => (float) $i['quantity']])->all();
        $batch = ! empty($d['batch_id']) ? $this->findCycle($request, $d['batch_id']) : null;
        $checks = collect($d['quality_checks'] ?? [])->filter(fn ($c) => ! empty($c['check']))->map(fn ($c) => ['check' => $c['check'], 'value' => $c['value'] ?? null,
            'ok' => filter_var($c['ok'] ?? true, FILTER_VALIDATE_BOOL), 'note' => $c['note'] ?? null])->values()->all();
        $op = $processing->run($farm, $d['kind'], $inputs, $d['outputs'], [], ['quality_checks' => $checks, 'loss_reason' => $d['loss_reason'] ?? null], $request->user(), $batch);

        return back()->with($op->quality_passed ? 'success' : 'warning', $op->quality_passed ? "{$op->reference} recorded." : "{$op->reference} recorded; a check failed, so what was made is held.");
    }

    public function release(Request $request, string $lot, Processing $processing): RedirectResponse
    {
        $this->authorize('process', Cycle::class);
        $d = $request->validate(['note' => ['required', 'string', 'max:500'], 'checks' => ['required', 'array', 'min:1'], 'checks.*.check' => ['required', 'string'],
            'checks.*.value' => ['nullable'], 'checks.*.ok' => ['required', 'boolean']]);
        $farms = $this->access->farmIdsFor($request->user());
        $l = ProduceLot::query()->where('uuid', $lot)->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))->firstOrFail();
        $processing->release($l, $d['checks'], $request->user(), $d['note']);

        return back()->with('success', "Lot {$l->code} released.");
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function recordRules(Request $request): array
    {
        return $request->validate(['kind' => ['required', Rule::in(Record::KINDS)], 'recorded_at' => ['nullable', 'date'], 'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'], 'count' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:120'], 'grade' => ['nullable', 'string', 'max:40'],
            'cost' => ['nullable', 'numeric', 'min:0'], 'sample_size' => ['nullable', 'integer', 'min:1'], 'uniformity_pct' => ['nullable', 'numeric', 'between:0,100'],
            'product_name' => ['nullable', 'string', 'max:120'], 'withdrawal_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'dose' => ['nullable', 'string', 'max:60'],
            'destination' => ['nullable', Rule::in(['store', 'sold', 'loss'])], 'stock_item_id' => ['nullable', 'integer'], 'store_id' => ['nullable', 'string'],
            'unit_value' => ['nullable', 'numeric', 'min:0'], 'client_id' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:500']]);
    }

    private function recordData(Request $request, array $d): array
    {
        return collect($d)->except('kind')->filter(fn ($v) => $v !== null && $v !== '')->all();
    }

    private function cycleRow(Cycle $c): array
    {
        return ['id' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'status' => $c->status, 'stage' => $c->stage, 'type' => $c->type?->name,
            'category' => $c->type?->category, 'count_unit' => $c->type?->count_unit, 'unit' => $c->unit?->name, 'farm' => $c->farm?->name,
            'started_on' => $c->started_on?->toDateString(), 'age_days' => $c->ageDays(), 'current_count' => $c->current_count, 'area' => $c->area, 'area_unit' => $c->area_unit];
    }

    private function options(Request $request): array
    {
        $farms = $this->access->farmIdsFor($request->user());

        return [
            'types' => ProductionType::query()->where('is_active', true)->orderBy('category')->orderBy('name')->get(['uuid', 'name', 'category'])
                ->map(fn ($t) => ['value' => $t->uuid, 'title' => $t->name, 'category' => $t->category]),
            'units' => $this->scoped(Unit::query(), $request)->whereNotIn('status', ['retired'])->orderBy('code')->get(['uuid', 'code', 'name', 'kind'])
                ->map(fn ($u) => ['value' => $u->uuid, 'title' => "{$u->code} — {$u->name}", 'kind' => $u->kind]),
            'farms' => Farm::query()->where('status', Farm::ACTIVE)->when($farms !== null, fn ($q) => $q->whereIn('id', $farms))->orderBy('name')->get(['uuid', 'name'])
                ->map(fn ($f) => ['value' => $f->uuid, 'title' => $f->name]),
            'plans' => CropPlan::query()->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))
                ->orderBy('code')->get(['uuid', 'code'])->map(fn ($p) => ['value' => $p->uuid, 'title' => $p->code]),
            'fields' => FarmField::query()->when($farms !== null, fn ($q) => $q->whereIn('farm_id', $farms))->orderBy('code')->get(['uuid', 'code', 'name', 'farm_id'])
                ->map(fn ($f) => ['value' => $f->uuid, 'title' => "{$f->code} — {$f->name}"]),
        ];
    }

    private function can(Request $request): array
    {
        $u = $request->user();

        return ['manage' => $u->can('manage', Cycle::class), 'record' => $u->can('record', Cycle::class), 'process' => $u->can('process', Cycle::class)];
    }

    /** Only the farms the viewer may reach; records without a farm (a processing line) are the organisation's. */
    private function scoped($query, Request $request)
    {
        $farms = $this->access->farmIdsFor($request->user());

        return $farms === null ? $query : $query->where(fn ($q) => $q->whereNull('farm_id')->orWhereIn('farm_id', $farms));
    }

    private function findCycle(Request $request, ?string $uuid): Cycle
    {
        return $this->scoped(Cycle::query(), $request)->where('uuid', (string) $uuid)->firstOrFail();
    }

    private function reachableFarm(Request $request, string $uuid): Farm
    {
        $farm = Farm::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->access->canReach($request->user(), $farm->id), 404);

        return $farm;
    }

    /** An organisation starts with the default production types the first time it opens these pages. */
    private function ensureTypes(Request $request): void
    {
        if (! ProductionType::query()->exists() && ($company = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id)) {
            $this->types->installDefaults((int) $company);
        }
    }
}
