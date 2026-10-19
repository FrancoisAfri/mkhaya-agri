<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Services\AgriCatalogue;
use App\Modules\Agri\Services\CropPlanService;
use App\Modules\Agri\Services\FieldWork;
use App\Modules\Agri\Services\Harvests;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module B: crop plans, field activities and their inputs, scouting observations and harvests.
 */
class CropController extends AgriController
{
    public function __construct(
        private readonly CropPlanService $plans,
        private readonly FieldWork $work,
        private readonly Harvests $harvests,
    ) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_CROPS);
        app(AgriCatalogue::class)->ensureDefaults();
        $farm = $this->farmFilter($request);
        $seasonId = $request->query('season');
        $status = $request->query('status', 'live');
        $farmScope = fn ($q) => $q->when($farm, fn ($w) => $w->where('farm_id', $farm->id));

        $plans = CropPlan::query()->tap($farmScope)
            ->when($seasonId, fn ($q) => $q->whereHas('season', fn ($s) => $s->where('uuid', $seasonId)))
            ->when($status === 'live', fn ($q) => $q->whereIn('status', CropPlan::LIVE))
            ->when(! in_array($status, ['live', 'all'], true), fn ($q) => $q->where('status', $status))
            ->with(['farm', 'field', 'cropType', 'variety', 'season'])
            ->orderBy('planned_planting_on')->get()
            ->map(fn (CropPlan $p) => $this->planRow($p));

        $activities = FieldActivity::query()->tap($farmScope)
            ->with(['farm', 'field', 'cropPlan', 'livestockGroup'])->withSum(['inputs as input_cost' => fn ($q) => $q->where('status', 'issued')], 'total_cost')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END")->orderByDesc('planned_on')
            ->limit(200)->get()->map(fn (FieldActivity $a) => $this->activityRow($a));

        $observations = CropObservation::query()->tap($farmScope)->with(['farm', 'field', 'cropPlan'])
            ->orderByDesc('observed_on')->limit(100)->get()
            ->map(fn (CropObservation $o) => $this->observationRow($o));

        $harvests = HarvestRecord::query()->tap($farmScope)->with(['farm', 'field', 'cropPlan.cropType'])
            ->orderByDesc('harvested_on')->limit(100)->get()
            ->map(fn (HarvestRecord $h) => [
                'id' => $h->uuid, 'farm' => $h->farm?->name, 'field' => $h->field?->code, 'plan' => $h->cropPlan?->code,
                'plan_id' => $h->cropPlan?->uuid, 'crop' => $h->cropPlan?->cropType?->name,
                'harvested_on' => $h->harvested_on->toDateString(), 'quantity' => (float) $h->quantity, 'unit' => $h->unit,
                'grade' => $h->grade, 'destination' => $h->destination, 'value' => $h->unit_value ? round((float) $h->unit_value * (float) $h->quantity, 2) : null,
            ]);

        return Inertia::render('Modules/Agri/Crops/Index', [
            'plans' => $plans,
            'activities' => $activities,
            'observations' => $observations,
            'harvests' => $harvests,
            'filters' => ['farm' => $farm?->uuid, 'season' => $seasonId, 'status' => $status, 'tab' => $request->query('tab', 'plans')],
            'options' => $this->options(),
            'can' => $this->can($request),
        ]);
    }

    public function show(Request $request, CropPlan $plan): Response
    {
        $this->allow($request, R::VIEW_CROPS);
        $plan->load(['farm', 'field', 'cropType', 'variety', 'season']);

        $activities = $plan->fieldActivities()->with(['inputs.stockItem', 'field', 'farm', 'machine'])->orderBy('planned_on')->get();
        $costs = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $plan->company_id)->whereIn('a.type', ['income', 'expense'])
            ->whereRaw("l.dimensions->>'agri_enterprise' = ?", ['crop_plan:'.$plan->id])
            ->selectRaw("a.type, COALESCE(l.dimensions->>'agri_category','other') as category, SUM(l.base_debit - l.base_credit) as net")
            ->groupBy('a.type', DB::raw("COALESCE(l.dimensions->>'agri_category','other')"))->get();
        $spent = round((float) $costs->where('type', 'expense')->sum('net'), 2);
        $income = round(-(float) $costs->where('type', 'income')->sum('net'), 2);
        $until = $this->work->withholdingUntil($plan);

        return Inertia::render('Modules/Agri/Crops/Plan', [
            'plan' => $this->planRow($plan) + [
                'notes' => $plan->notes, 'cancel_reason' => $plan->cancel_reason,
                'approved_at' => $plan->approved_at?->toDateTimeString(),
                'field_hectares' => $plan->field?->hectares(),
                'transitions' => collect(['approve' => CropPlan::APPROVED, 'plant' => CropPlan::PLANTED, 'complete' => CropPlan::COMPLETED,
                    'cancel' => CropPlan::CANCELLED, 'reopen' => CropPlan::DRAFT])
                    ->filter(fn ($to) => $this->plans->canMove($plan, $to))->keys()->values(),
            ],
            'activities' => $activities->map(fn (FieldActivity $a) => $this->activityRow($a) + [
                'inputs' => $a->inputs->map(fn (ActivityInput $i) => [
                    'id' => $i->uuid, 'item' => $i->stockItem?->name, 'quantity' => (float) $i->quantity, 'uom' => $i->uom,
                    'rate_per_ha' => self::num($i->rate_per_ha), 'withholding_days' => $i->withholding_days,
                    'total_cost' => self::num($i->total_cost), 'status' => $i->status,
                ]),
            ]),
            'harvests' => $plan->harvests()->orderBy('harvested_on')->get()->map(fn (HarvestRecord $h) => [
                'id' => $h->uuid, 'harvested_on' => $h->harvested_on->toDateString(), 'quantity' => (float) $h->quantity, 'unit' => $h->unit,
                'grade' => $h->grade, 'moisture_pct' => self::num($h->moisture_pct), 'destination' => $h->destination,
                'unit_value' => self::num($h->unit_value), 'override_reason' => $h->override_reason,
            ]),
            'observations' => CropObservation::query()->where('crop_plan_id', $plan->id)->orderByDesc('observed_on')->get()->map(fn ($o) => $this->observationRow($o)),
            'yields' => $this->harvests->yieldFor($plan),
            'finance' => ['spent' => $spent, 'income' => $income, 'budget' => self::num($plan->budget_amount),
                'by_category' => $costs->where('type', 'expense')->mapWithKeys(fn ($c) => [$c->category => round((float) $c->net, 2)])],
            'withholding_until' => $until?->toDateString(),
            'options' => $this->options(),
            'can' => $this->can($request),
        ]);
    }

    // ── Plans ────────────────────────────────────────────────────────────────────────────────

    public function storePlan(Request $request): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $data = $request->validate($this->planRules() + ['farm_id' => ['required', 'string']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id']);
        $plan = $this->plans->create($farm, $field, $this->planData($data), $request->user());

        return redirect()->route('agri.crops.plans.show', $plan->uuid)->with('success', "Crop plan {$plan->code} drafted.");
    }

    public function updatePlan(Request $request, CropPlan $plan): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $this->assertWritable($request, $plan->farm_id);
        $data = $request->validate($this->planRules());
        $this->plans->update($plan, $this->fieldOn($plan->farm, $data['field_id']), $this->planData($data));

        return back()->with('success', 'Crop plan updated.');
    }

    public function transition(Request $request, CropPlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'plant', 'complete', 'cancel', 'reopen'])],
            'date' => ['nullable', 'date'],
            'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:500'],
        ]);
        $this->allow($request, $data['action'] === 'approve' ? R::APPROVE_CROPS : R::PLAN_CROPS);
        $this->assertWritable($request, $plan->farm_id);
        $user = $request->user();
        $date = $data['date'] ?? today()->toDateString();

        match ($data['action']) {
            'approve' => $this->plans->approve($plan, $user),
            'plant' => $this->plans->plant($plan, $date),
            'complete' => $this->plans->complete($plan, $date),
            'cancel' => $this->plans->cancel($plan, $data['reason']),
            'reopen' => $plan->status === CropPlan::APPROVED ? $plan->update(['status' => CropPlan::DRAFT, 'approved_by' => null, 'approved_at' => null]) : null,
        };

        return back()->with('success', "Plan {$plan->code} is now {$plan->fresh()->status}.");
    }

    // ── Activities and inputs ────────────────────────────────────────────────────────────────

    public function storeActivity(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'field_id' => ['nullable', 'string'],
            'crop_plan_id' => ['nullable', 'string'],
            'livestock_group_id' => ['nullable', 'string'],
            'activity_type' => ['required', Rule::in(FieldActivity::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'planned_on' => ['required', 'date'],
            'assigned_to' => ['nullable', 'integer'],
            'machine_id' => ['nullable', 'string'],
            'area_covered' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $plan = ! empty($data['crop_plan_id']) ? CropPlan::query()->where('uuid', $data['crop_plan_id'])->firstOrFail() : null;
        $group = ! empty($data['livestock_group_id']) ? LivestockGroup::query()->where('uuid', $data['livestock_group_id'])->firstOrFail() : null;
        $machine = ! empty($data['machine_id']) ? FarmMachine::query()->where('uuid', $data['machine_id'])->where('farm_id', $farm->id)->firstOrFail() : null;

        $activity = $this->work->create($farm, $this->fieldOn($farm, $data['field_id'] ?? null), $plan, $group, [
            'activity_type' => $data['activity_type'], 'title' => $data['title'], 'planned_on' => $data['planned_on'],
            'assigned_to' => $data['assigned_to'] ?? null, 'machine_id' => $machine?->id,
            'area_covered' => $data['area_covered'] ?? null, 'notes' => $data['notes'] ?? null,
        ], $request->user());

        return back()->with('success', "Activity \"{$activity->title}\" planned.");
    }

    public function storeInput(Request $request, FieldActivity $activity): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $activity->farm_id);
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'rate_per_ha' => ['nullable', 'numeric', 'gte:0'],
            'withholding_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);
        $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
        $this->work->addInput($activity, $item, $data);

        return back()->with('success', "{$item->name} added to the activity. It is issued from the farm store when the work is completed.");
    }

    public function destroyInput(Request $request, FieldActivity $activity, ActivityInput $input): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        abort_unless((int) $input->activity_id === (int) $activity->id, 404);
        $this->assertWritable($request, $activity->farm_id);
        $this->work->removeInput($input);

        return back()->with('success', 'Input removed.');
    }

    public function activityStatus(Request $request, FieldActivity $activity): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $activity->farm_id);
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'complete', 'cancel'])],
            'completed_at' => ['nullable', 'date'],
            'area_covered' => ['nullable', 'numeric', 'gt:0'],
            'labour_hours' => ['nullable', 'numeric', 'gte:0'],
            'labour_cost' => ['nullable', 'numeric', 'gte:0'],
            'machine_hours' => ['nullable', 'numeric', 'gte:0'],
            'weather' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        match ($data['action']) {
            'start' => $this->work->start($activity),
            'complete' => $this->work->complete($activity, $data, $request->user()),
            'cancel' => $this->work->cancel($activity),
        };

        return back()->with('success', $data['action'] === 'complete'
            ? 'Activity completed; its inputs were issued from the farm store.' : 'Activity updated.');
    }

    // ── Observations and harvests ────────────────────────────────────────────────────────────

    public function storeObservation(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'field_id' => ['nullable', 'string'],
            'crop_plan_id' => ['nullable', 'string'],
            'observed_on' => ['required', 'date', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(CropObservation::KINDS)],
            'severity' => ['required', Rule::in(CropObservation::SEVERITIES)],
            'growth_stage' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:3000'],
            'action_required' => ['boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $plan = ! empty($data['crop_plan_id']) ? CropPlan::query()->where('uuid', $data['crop_plan_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $field = $this->fieldOn($farm, $data['field_id'] ?? null) ?? $plan?->field;

        CropObservation::create([
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'field_id' => $field?->id, 'crop_plan_id' => $plan?->id,
            'recorded_by' => $request->user()->id, 'status' => 'open',
        ] + collect($data)->except(['farm_id', 'field_id', 'crop_plan_id'])->all());

        return back()->with('success', 'Observation recorded.');
    }

    public function updateObservation(Request $request, CropObservation $observation): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $observation->farm_id);
        $observation->update($request->validate(['status' => ['required', Rule::in(CropObservation::STATUSES)]]));

        return back()->with('success', 'Observation updated.');
    }

    public function storeHarvest(Request $request, CropPlan $plan): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $this->assertWritable($request, $plan->farm_id);
        $data = $request->validate([
            'harvested_on' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', Rule::in(CropType::YIELD_UNITS)],
            'grade' => ['nullable', 'string', 'max:40'],
            'moisture_pct' => ['nullable', 'numeric', 'between:0,100'],
            'area_harvested' => ['nullable', 'numeric', 'gt:0'],
            'destination' => ['required', Rule::in(HarvestRecord::DESTINATIONS)],
            'stock_item_id' => ['nullable', 'integer'],
            'unit_value' => ['nullable', 'numeric', 'gte:0'],
            'settled_by' => ['nullable', Rule::in(['bank', 'receivable'])],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->harvests->record($plan, $data, $request->user());

        return back()->with('success', 'Harvest recorded.');
    }

    // ── Shapes ───────────────────────────────────────────────────────────────────────────────

    private function planRow(CropPlan $p): array
    {
        return [
            'id' => $p->uuid, 'code' => $p->code, 'status' => $p->status,
            'farm' => $p->farm?->name, 'farm_id' => $p->farm?->uuid,
            'field' => $p->field?->code, 'field_name' => $p->field?->name, 'field_id' => $p->field?->uuid,
            'season' => $p->season?->name, 'season_id' => $p->season_id,
            'crop' => $p->cropType?->name, 'crop_type_id' => $p->crop_type_id,
            'variety' => $p->variety?->name, 'variety_id' => $p->variety_id,
            'planned_area' => (float) $p->planned_area, 'area_unit' => $p->area_unit, 'hectares' => round($p->hectares(), 4),
            'planned_planting_on' => $p->planned_planting_on?->toDateString(), 'planned_harvest_on' => $p->planned_harvest_on?->toDateString(),
            'planted_on' => $p->planted_on?->toDateString(), 'harvest_completed_on' => $p->harvest_completed_on?->toDateString(),
            'expected_yield' => self::num($p->expected_yield), 'yield_unit' => $p->yield_unit,
            'budget_amount' => self::num($p->budget_amount), 'expected_price' => self::num($p->expected_price),
        ];
    }

    private function activityRow(FieldActivity $a): array
    {
        return [
            'id' => $a->uuid, 'title' => $a->title, 'activity_type' => $a->activity_type, 'status' => $a->status,
            'farm' => $a->farm?->name, 'farm_id' => $a->farm?->uuid, 'field' => $a->field?->code,
            'plan' => $a->cropPlan?->code, 'plan_id' => $a->cropPlan?->uuid, 'group' => $a->livestockGroup?->code,
            'planned_on' => $a->planned_on?->toDateString(), 'completed_at' => $a->completed_at?->toDateString(),
            'area_covered' => self::num($a->area_covered), 'labour_hours' => self::num($a->labour_hours),
            'labour_cost' => self::num($a->labour_cost), 'machine' => $a->machine?->code, 'machine_hours' => self::num($a->machine_hours),
            'input_cost' => isset($a->input_cost) ? round((float) $a->input_cost, 2) : null, 'notes' => $a->notes,
        ];
    }

    private function observationRow(CropObservation $o): array
    {
        return [
            'id' => $o->uuid, 'farm' => $o->farm?->name, 'field' => $o->field?->code, 'plan' => $o->cropPlan?->code,
            'observed_on' => $o->observed_on->toDateString(), 'kind' => $o->kind, 'severity' => $o->severity,
            'growth_stage' => $o->growth_stage, 'description' => $o->description, 'action_required' => $o->action_required,
            'status' => $o->status,
        ];
    }

    private function planRules(): array
    {
        return [
            'field_id' => ['required', 'string'],
            'season_id' => ['required', 'integer'],
            'crop_type_id' => ['required', 'integer'],
            'variety_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'],
            'planned_area' => ['required', 'numeric', 'gt:0'],
            'area_unit' => ['required', Rule::in(Farm::AREA_UNITS)],
            'planned_planting_on' => ['required', 'date'],
            'planned_harvest_on' => ['required', 'date'],
            'expected_yield' => ['nullable', 'numeric', 'gte:0'],
            'yield_unit' => ['required', Rule::in(CropType::YIELD_UNITS)],
            'budget_amount' => ['nullable', 'numeric', 'gte:0'],
            'expected_price' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }

    private function planData(array $data): array
    {
        return collect($data)->except(['farm_id', 'field_id'])->all();
    }

    private function options(): array
    {
        $items = FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom', 'avg_cost']);

        return [
            'farms' => $this->farmOptions(),
            'seasons' => CropSeason::query()->orderByDesc('starts_on')->get(['id', 'uuid', 'code', 'name', 'starts_on', 'ends_on', 'status'])
                ->map(fn ($s) => ['id' => $s->id, 'uuid' => $s->uuid, 'name' => $s->name, 'status' => $s->status,
                    'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString()]),
            'crop_types' => CropType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'yield_unit', 'category']),
            'varieties' => CropVariety::query()->where('is_active', true)->orderBy('name')->get(['id', 'crop_type_id', 'name', 'days_to_maturity']),
            'plans' => CropPlan::query()->whereIn('status', CropPlan::WORKABLE)->with(['farm:id,uuid', 'field:id,uuid,code'])->orderBy('code')->get()
                ->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'farm_id' => $p->farm?->uuid, 'field_id' => $p->field?->uuid]),
            'groups' => LivestockGroup::query()->where('status', 'active')->with('farm:id,uuid')->orderBy('code')->get()
                ->map(fn ($g) => ['id' => $g->uuid, 'code' => $g->code, 'name' => $g->name, 'farm_id' => $g->farm?->uuid]),
            'machines' => FarmMachine::query()->where('status', '!=', 'disposed')->with('farm:id,uuid')->orderBy('code')->get()
                ->map(fn ($m) => ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'farm_id' => $m->farm?->uuid]),
            'stock_items' => $items->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom]),
            'people' => $this->tenantPeopleOptions(),
            'activity_types' => FieldActivity::TYPES,
            'statuses' => CropPlan::STATUSES,
            'kinds' => CropObservation::KINDS,
            'severities' => CropObservation::SEVERITIES,
            'destinations' => HarvestRecord::DESTINATIONS,
            'yield_units' => CropType::YIELD_UNITS,
            'area_units' => Farm::AREA_UNITS,
        ];
    }

    private function can(Request $request): array
    {
        $u = $request->user();

        return [
            'plan' => $u->can(R::PLAN_CROPS), 'approve' => $u->can(R::APPROVE_CROPS),
            'record' => $u->can(R::RECORD_ACTIVITIES), 'harvest' => $u->can(R::RECORD_HARVEST),
        ];
    }
}
