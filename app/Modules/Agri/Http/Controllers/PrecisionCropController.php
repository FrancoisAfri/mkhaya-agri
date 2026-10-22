<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropInputTemplate;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\IrrigationException;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\ObservationPhoto;
use App\Modules\Agri\Models\OverheadRule;
use App\Modules\Agri\Models\ScoutingThreshold;
use App\Modules\Agri\Models\SoilMoistureReading;
use App\Modules\Agri\Models\SoilTest;
use App\Modules\Agri\Services\CropDiagnosisAI;
use App\Modules\Agri\Services\CropLifecycle;
use App\Modules\Agri\Services\CropProfitability;
use App\Modules\Agri\Services\FieldWork;
use App\Modules\Agri\Services\HarvestPlanning;
use App\Modules\Agri\Services\InputPlanning;
use App\Modules\Agri\Services\Irrigation;
use App\Modules\Agri\Services\Scouting;
use App\Modules\Agri\Services\WaterBalance;
use App\Modules\Agri\Models\CropCoefficient;
use App\Modules\Agri\Models\IrrigationRecommendation;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §3: precision crop and production management — one page with a tab per part (scouting,
 * irrigation, harvest, input planning, profitability) and the actions behind them. The crop plan
 * page shows the same for one crop.
 */
class PrecisionCropController extends AgriController
{
    use FileStorage;

    public const TABS = ['scouting', 'irrigation', 'harvest', 'inputs', 'profitability'];

    public function index(Request $request, string $tab = 'scouting'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, $tab === 'profitability' ? R::VIEW_FINANCE : R::VIEW_CROPS);
        $farm = $this->farmFilter($request);
        $season = $request->query('season') ? CropSeason::query()->where('uuid', $request->query('season'))->first() : null;
        $plans = CropPlan::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->when($season, fn ($q) => $q->where('season_id', $season->id))
            ->with(['cropType', 'variety', 'field', 'season'])->orderBy('code')->get();

        return Inertia::render('Modules/Agri/Crops/Precision', [
            'tab' => $tab,
            'filters' => ['farm' => $farm?->uuid, 'season' => $season?->uuid, 'by' => $request->query('by', 'variety')],
            'data' => match ($tab) {
                'scouting' => $this->scoutingData($farm),
                'irrigation' => $this->irrigationData($farm),
                'harvest' => $this->harvestData($plans),
                'inputs' => $this->inputsData($request, $plans),
                'profitability' => $this->profitData($request, $plans),
            },
            'options' => [
                'farms' => $this->farmOptions(true),
                'seasons' => CropSeason::query()->orderByDesc('starts_on')->get(['uuid', 'name'])->map(fn ($s) => ['id' => $s->uuid, 'name' => $s->name]),
                'plans' => $plans->whereIn('status', CropPlan::LIVE)->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'farm_id' => $p->farm?->uuid ?? null,
                    'field_id' => $p->field?->uuid, 'crop' => $p->cropType?->name, 'unit' => $p->yield_unit, 'crop_type_id' => $p->crop_type_id])->values(),
                'crop_types' => CropType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'yield_unit']),
                'kinds' => CropObservation::KINDS, 'severities' => CropObservation::SEVERITIES,
                'people' => $this->tenantPeopleOptions($request),
            ],
            'can' => ['record' => $request->user()->can(R::RECORD_ACTIVITIES), 'plan' => $request->user()->can(R::PLAN_CROPS),
                'approve' => $request->user()->can(R::APPROVE_CROPS), 'review' => $request->user()->can(R::REVIEW_DIAGNOSIS),
                'harvest' => $request->user()->can(R::RECORD_HARVEST), 'finance' => $request->user()->can(R::MANAGE_FINANCE),
                'assistant' => $request->user()->can(R::USE_ASSISTANT)],
        ]);
    }

    // ── §3.1 lifecycle ────────────────────────────────────────────────────────────────────────

    public function stage(Request $request, CropPlan $plan, CropLifecycle $life): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $plan->farm_id);
        $data = $request->validate(['stage' => ['required', 'string', 'max:40'], 'observed_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
        $life->recordStage($plan, $data['stage'], $data['observed_on'], $data['notes'] ?? null, $request->user());

        return back()->with('success', 'Growth stage recorded.');
    }

    public function rotation(Request $request, CropLifecycle $life): JsonResponse
    {
        $this->allow($request, R::VIEW_CROPS);
        $field = FarmField::query()->where('uuid', $request->query('field'))->firstOrFail();

        return response()->json($life->rotation($field, $request->integer('crop_type_id') ?: null, $request->query('before')));
    }

    public function split(Request $request, FarmField $field, CropLifecycle $life): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->assertWritable($request, $field->farm_id);
        $data = $request->validate(['parts' => ['required', 'array', 'min:2', 'max:12'], 'parts.*.code' => ['required', 'string', 'max:30'],
            'parts.*.name' => ['required', 'string', 'max:120'], 'parts.*.area' => ['required', 'numeric', 'gt:0']]);
        $parts = $life->split($field, $data['parts'], $request->user());

        return back()->with('success', "{$field->code} split into ".collect($parts)->pluck('code')->implode(', ').'.');
    }

    public function soilTest(Request $request, FarmField $field, CropLifecycle $life): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $field->farm_id);
        $num = ['nullable', 'numeric', 'gte:0'];
        $data = $request->validate(['sampled_on' => ['required', 'date'], 'laboratory' => ['nullable', 'string', 'max:120'], 'sample_ref' => ['nullable', 'string', 'max:60'],
            'depth_cm' => ['nullable', 'integer', 'min:0', 'max:300'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'ph' => ['nullable', 'numeric', 'between:0,14'], 'organic_carbon_pct' => $num, 'n_mg_kg' => $num, 'p_mg_kg' => $num, 'k_mg_kg' => $num, 'ca_mg_kg' => $num,
            'mg_mg_kg' => $num, 'ec_ms_m' => $num, 'recommendation' => ['nullable', 'string', 'max:3000'],
            'report' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,csv']]);
        if ($request->hasFile('report')) {
            $f = $this->saveFileToDatabase($request->file('report'));
            $data += ['report_name' => $f['original_name'], 'report_mime' => $f['mime'], 'report_data' => $f['data']];
        }
        $life->recordSoilTest($field, $data, $request->user());

        return back()->with('success', 'Soil test recorded.');
    }

    public function soilReport(Request $request, SoilTest $test)
    {
        $this->allow($request, R::VIEW_CROPS);
        abort_unless($test->report_data, 404);

        return response(base64_decode($test->report_data), 200, ['Content-Type' => $test->report_mime === 'application/pdf' ? 'application/pdf' : 'application/octet-stream',
            'Content-Disposition' => ($test->report_mime === 'application/pdf' ? 'inline' : 'attachment').'; filename="'.addslashes($test->report_name ?? 'soil-test').'"',
            'X-Content-Type-Options' => 'nosniff']);
    }

    // ── §3.2 inputs ───────────────────────────────────────────────────────────────────────────

    public function saveTemplate(Request $request, ?CropInputTemplate $template = null): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $data = $request->validate(['crop_type_id' => ['required', 'integer'], 'production_method' => ['nullable', 'string', 'max:30'],
            'category' => ['required', Rule::in(CropInputTemplate::CATEGORIES)], 'stock_item_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:200'], 'rate' => ['required', 'numeric', 'gt:0'], 'basis' => ['required', Rule::in(['per_ha', 'per_t_yield'])],
            'unit' => ['required', 'string', 'max:20'], 'timing_days' => ['nullable', 'integer', 'between:-365,730'], 'guidance_ref' => ['nullable', 'string', 'max:200']]);
        CropType::query()->findOrFail($data['crop_type_id']);
        if ($template?->exists) {
            // A changed rate is new advice: it goes back for approval.
            $template->update($data + ['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        } else {
            CropInputTemplate::create($data + ['company_id' => $request->user()->company_id, 'status' => 'draft', 'created_by' => $request->user()->id]);
        }

        return back()->with('success', 'Input rate saved; it counts once somebody approves it.');
    }

    public function approveTemplate(Request $request, CropInputTemplate $template, InputPlanning $planning): RedirectResponse
    {
        $this->allow($request, R::APPROVE_CROPS);
        $planning->approveTemplate($template, $request->user());

        return back()->with('success', 'Input rate approved.');
    }

    public function retireTemplate(Request $request, CropInputTemplate $template): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $template->update(['status' => 'retired']);

        return back()->with('success', 'Input rate retired.');
    }

    /** §3.6 acceptance: correct an input's actual quantity — a reversal and a new issue, never an edit. */
    public function correctInput(Request $request, ActivityInput $input, FieldWork $work): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $input->farm_id);
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gte:0'], 'reason' => ['required', 'string', 'max:300']]);
        $work->correctInput($input, (float) $data['quantity'], $data['reason'], $request->user());

        return back()->with('success', 'Corrected. The original issue is reversed and kept; the cost reports show the corrected figure.');
    }

    // ── §3.3 scouting ─────────────────────────────────────────────────────────────────────────

    public function observe(Request $request, Scouting $scouting): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate([
            'farm_id' => ['required', 'string'], 'field_id' => ['nullable', 'string'], 'crop_plan_id' => ['nullable', 'string'],
            'observed_on' => ['required', 'date', 'before_or_equal:today'], 'kind' => ['required', Rule::in(CropObservation::KINDS)],
            'severity' => ['required', Rule::in(CropObservation::SEVERITIES)], 'growth_stage' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:3000'], 'action_required' => ['boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'affected_area_ha' => ['nullable', 'numeric', 'gte:0'], 'incidence_pct' => ['nullable', 'numeric', 'between:0,100'],
            'recommended_action' => ['nullable', 'string', 'max:500'], 'responsible_user_id' => ['nullable', 'integer'], 'follow_up_on' => ['nullable', 'date'],
            'photos' => ['nullable', 'array', 'max:6'], 'photos.*' => ['image', 'max:8192'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $plan = ! empty($data['crop_plan_id']) ? CropPlan::query()->where('uuid', $data['crop_plan_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $field = $this->fieldOn($farm, $data['field_id'] ?? null) ?? $plan?->field;
        if (! empty($data['responsible_user_id'])) {
            abort_unless(in_array((int) $data['responsible_user_id'], array_map('intval', $this->tenantPeopleIds($request)), true), 422, 'Choose somebody in this organisation.');
        }
        $photos = array_map(function ($file) {
            $f = $this->saveFileToDatabase($file);

            return ['name' => $f['original_name'], 'mime' => $f['mime'], 'data' => $f['data']];
        }, $request->file('photos', []));
        $scouting->record($farm, $field, $plan, $data, $photos, $request->user());

        return back()->with('success', 'Observation recorded.');
    }

    public function photo(Request $request, ObservationPhoto $photo)
    {
        $this->allow($request, R::VIEW_CROPS);
        $mime = in_array($photo->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic'], true) ? $photo->mime : 'application/octet-stream';

        return response(base64_decode($photo->data), 200, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function suggest(Request $request, CropObservation $observation, CropDiagnosisAI $ai): RedirectResponse
    {
        $this->allow($request, R::USE_ASSISTANT);
        $this->assertWritable($request, $observation->farm_id);
        try {
            $ai->suggest($observation);
        } catch (\Throwable $e) {
            return back()->with('error', 'The AI could not suggest anything just now: '.mb_strimwidth($e->getMessage(), 0, 160));
        }

        return back()->with('success', 'Possible diagnoses added. A qualified person reviews them before any treatment.');
    }

    public function review(Request $request, CropObservation $observation, Scouting $scouting): RedirectResponse
    {
        $this->allow($request, R::REVIEW_DIAGNOSIS);
        $this->assertWritable($request, $observation->farm_id);
        $scouting->review($observation, $request->validate(['note' => ['required', 'string', 'max:500']])['note'], $request->user());

        return back()->with('success', 'Review recorded.');
    }

    public function treat(Request $request, CropObservation $observation, Scouting $scouting): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $observation->farm_id);
        $activity = FieldActivity::query()->where('uuid', $request->validate(['activity_id' => ['required', 'string']])['activity_id'])->firstOrFail();
        $scouting->linkTreatment($observation, $activity);

        return back()->with('success', 'Treatment linked.');
    }

    public function closeObservation(Request $request, CropObservation $observation, Scouting $scouting): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $observation->farm_id);
        $scouting->close($observation, $request->input('note'), $request->user());

        return back()->with('success', 'Observation closed.');
    }

    public function saveThreshold(Request $request, ?ScoutingThreshold $threshold = null): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $data = $request->validate(['crop_type_id' => ['nullable', 'integer'], 'kind' => ['required', Rule::in(CropObservation::KINDS)],
            'min_severity' => ['required', Rule::in(CropObservation::SEVERITIES)], 'min_incidence_pct' => ['nullable', 'numeric', 'between:0,100'],
            'count' => ['required', 'integer', 'min:1', 'max:50'], 'within_days' => ['required', 'integer', 'min:1', 'max:90'],
            'escalate_after_hours' => ['required', 'integer', 'min:1', 'max:720'], 'is_active' => ['boolean']]);
        $threshold?->exists ? $threshold->update($data) : ScoutingThreshold::create($data + ['company_id' => $request->user()->company_id]);

        return back()->with('success', 'Threshold saved.');
    }

    // ── §3.4 irrigation ───────────────────────────────────────────────────────────────────────

    public function saveZone(Request $request, ?IrrigationZone $zone = null): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['required', 'string'], 'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'], 'area_ha' => ['nullable', 'numeric', 'gt:0'], 'system' => ['nullable', Rule::in(FarmField::IRRIGATION)],
            'pump_machine_id' => ['nullable', 'string'], 'flow_m3_per_hour' => ['nullable', 'numeric', 'gt:0'], 'water_cost_per_m3' => ['nullable', 'numeric', 'gte:0'],
            'energy_kwh_per_hour' => ['nullable', 'numeric', 'gte:0'], 'energy_cost_per_kwh' => ['nullable', 'numeric', 'gte:0'], 'meter_ref' => ['nullable', 'string', 'max:80']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id']);
        $pump = ! empty($data['pump_machine_id']) ? FarmMachine::query()->where('uuid', $data['pump_machine_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $row = ['field_id' => $field->id, 'pump_machine_id' => $pump?->id] + collect($data)->except(['farm_id', 'field_id', 'pump_machine_id'])->all();
        $zone?->exists ? $zone->update($row) : IrrigationZone::create($row + ['company_id' => $farm->company_id, 'farm_id' => $farm->id]);

        return back()->with('success', 'Irrigation zone saved.');
    }

    public function planIrrigation(Request $request, Irrigation $irrigation): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['required', 'string'], 'zone_id' => ['nullable', 'string'],
            'planned_start' => ['required', 'date'], 'planned_minutes' => ['nullable', 'integer', 'min:1', 'max:2880'], 'planned_m3' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $irrigation->plan($this->fieldOn($farm, $data['field_id']), $data, $request->user());

        return back()->with('success', 'Irrigation planned.');
    }

    public function recordIrrigation(Request $request, IrrigationEvent $event, Irrigation $irrigation): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $event->farm_id);
        $data = $request->validate(['skip' => ['boolean'], 'reason' => ['nullable', 'required_if:skip,true', 'string', 'max:300'],
            'actual_start' => ['nullable', 'date'], 'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:2880'], 'actual_m3' => ['nullable', 'numeric', 'gte:0'],
            'source' => ['nullable', Rule::in(['manual', 'meter'])], 'notes' => ['nullable', 'string', 'max:500']]);
        ($data['skip'] ?? false) ? $irrigation->skip($event, $data['reason']) : $irrigation->record($event, $data, $request->user());

        return back()->with('success', ($data['skip'] ?? false) ? 'Irrigation skipped.' : 'Irrigation recorded.');
    }

    public function moisture(Request $request, Irrigation $irrigation): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['required', 'string'], 'read_at' => ['nullable', 'date'],
            'depth_cm' => ['nullable', 'integer', 'min:0', 'max:300'], 'vwc_pct' => ['required', 'numeric', 'between:0,100']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $irrigation->moisture($this->fieldOn($farm, $data['field_id']), $data, $request->user());

        return back()->with('success', 'Soil moisture recorded.');
    }

    public function irrigationProblem(Request $request, Irrigation $irrigation): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['nullable', 'string'], 'zone_id' => ['nullable', 'string'],
            'kind' => ['required', Rule::in(IrrigationException::KINDS)], 'note' => ['nullable', 'string', 'max:500']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $zone = ! empty($data['zone_id']) ? IrrigationZone::query()->where('uuid', $data['zone_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        abort_unless($zone || $field, 422, 'Say which field or zone.');
        $irrigation->exception($field?->id, $zone?->id, $data['kind'], $data['note'] ?? null, $request->user());

        return back()->with('success', 'Irrigation problem recorded; it stays on the alerts until resolved.');
    }

    public function resolveIrrigationProblem(Request $request, IrrigationException $exception, Irrigation $irrigation): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $exception->farm_id);
        $irrigation->resolve($exception, $request->validate(['resolution' => ['required', 'string', 'max:500']])['resolution'], $request->user());

        return back()->with('success', 'Resolved.');
    }

    // ── Irrigation scheduling from evapotranspiration ─────────────────────────────────────────

    public function planRecommendation(Request $request, IrrigationRecommendation $recommendation, WaterBalance $balance): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $recommendation->farm_id);
        $data = $request->validate(['planned_start' => ['nullable', 'date']]);
        $balance->plan($recommendation, $request->user(), $data['planned_start'] ?? null);

        return back()->with('success', 'Irrigation planned from the water balance.');
    }

    public function dismissRecommendation(Request $request, IrrigationRecommendation $recommendation, WaterBalance $balance): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $recommendation->farm_id);
        $balance->dismiss($recommendation, $request->user());

        return back()->with('success', 'Recommendation dismissed.');
    }

    public function saveCoefficient(Request $request, CropCoefficient $coefficient, WaterBalance $balance): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS);
        $data = $request->validate(['kc_ini' => ['required', 'numeric', 'between:0.05,2'], 'kc_mid' => ['required', 'numeric', 'between:0.05,2'], 'kc_end' => ['required', 'numeric', 'between:0.05,2'],
            'stage_ini_pct' => ['required', 'integer', 'between:0,100'], 'stage_dev_pct' => ['required', 'integer', 'between:0,100'], 'stage_mid_pct' => ['required', 'integer', 'between:0,100'],
            'stage_late_pct' => ['required', 'integer', 'between:0,100'], 'root_depth_m' => ['required', 'numeric', 'between:0.1,5'], 'depletion_p' => ['required', 'numeric', 'between:0.1,0.9'],
            'source' => ['nullable', 'string', 'max:200']]);
        $balance->saveCoefficient($coefficient, $data, $request->user());
        foreach (Farm::query()->operational()->get() as $farm) {
            \Illuminate\Support\Facades\Cache::forget("agri_water_balance:{$farm->id}");
        }

        return back()->with('success', 'Crop coefficients saved; the water balance is worked out again.');
    }

    public function refreshBalance(Request $request, WaterBalance $balance): RedirectResponse
    {
        $this->allow($request, R::VIEW_CROPS);
        $farm = $this->farm($request->validate(['farm_id' => ['required', 'string']])['farm_id']);
        $r = $balance->computeFarm($farm);

        return back()->with('success', "Water balance worked out for {$r['scopes']} zone(s); {$r['recommendations']} irrigation(s) recommended this week.");
    }

    // ── §3.5 harvest ──────────────────────────────────────────────────────────────────────────

    public function forecast(Request $request, CropPlan $plan, HarvestPlanning $harvest): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS, R::RECORD_HARVEST);
        $this->assertWritable($request, $plan->farm_id);
        $data = $request->validate(['method' => ['required', Rule::in(['model', 'manual'])], 'quantity' => ['required_if:method,manual', 'nullable', 'numeric', 'gt:0'],
            'ready_on' => ['nullable', 'date'], 'how' => ['required_if:method,manual', 'nullable', 'string', 'max:300']]);
        $data['method'] === 'model' ? $harvest->forecast($plan, $request->user())
            : $harvest->manualForecast($plan, (float) $data['quantity'], $data['ready_on'] ?? null, $data['how'], $request->user());

        return back()->with('success', 'Forecast made. It is an estimate; harvests record what was measured.');
    }

    public function commit(Request $request, HarvestPlanning $harvest): RedirectResponse
    {
        $this->allow($request, R::PLAN_CROPS, R::MANAGE_FINANCE);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'crop_plan_id' => ['nullable', 'string'], 'crop_type_id' => ['required_without:crop_plan_id', 'nullable', 'integer'],
            'buyer' => ['required', 'string', 'max:160'], 'channel' => ['nullable', Rule::in(['contract', 'market', 'packhouse', 'export', 'retail', 'other'])],
            'quantity' => ['required', 'numeric', 'gt:0'], 'unit' => ['required', 'string', 'max:12'], 'grade' => ['nullable', 'string', 'max:30'],
            'deliver_on' => ['required', 'date'], 'price_per_unit' => ['nullable', 'numeric', 'gte:0']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $plan = ! empty($data['crop_plan_id']) ? CropPlan::query()->where('uuid', $data['crop_plan_id'])->where('farm_id', $farm->id)->firstOrFail() : null;
        $harvest->commit($plan, (int) ($plan?->crop_type_id ?? $data['crop_type_id']), $farm->id, $farm->company_id, $data, $request->user());

        return back()->with('success', "Commitment to {$data['buyer']} recorded.");
    }

    public function deliver(Request $request, HarvestCommitment $commitment, HarvestPlanning $harvest): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $this->assertWritable($request, $commitment->farm_id);
        $record = HarvestRecord::query()->where('uuid', $request->validate(['harvest_id' => ['required', 'string']])['harvest_id'])->firstOrFail();
        $harvest->deliver($commitment, $record);

        return back()->with('success', 'Delivery recorded against the commitment.');
    }

    // ── §3.6 profitability ────────────────────────────────────────────────────────────────────

    public function saveOverhead(Request $request, ?OverheadRule $rule = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'name' => ['required', 'string', 'max:120'], 'basis' => ['required', Rule::in(['per_ha', 'pct_direct'])],
            'rate' => ['required', 'numeric', 'gte:0'], 'valid_from' => ['required', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'], 'is_active' => ['boolean']]);
        $farm = ! empty($data['farm_id']) ? $this->workingFarm($request, $data['farm_id']) : null;
        $row = ['farm_id' => $farm?->id] + collect($data)->except('farm_id')->all();
        $rule?->exists ? $rule->update($row) : OverheadRule::create($row + ['company_id' => $request->user()->company_id]);

        return back()->with('success', 'Overhead rule saved.');
    }

    // ── Tab data ──────────────────────────────────────────────────────────────────────────────

    private function scoutingData($farm): array
    {
        $obs = CropObservation::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->where(fn ($q) => $q->where('status', '!=', 'closed')->orWhereDate('observed_on', '>=', today()->subDays(60)))
            ->withCount('photos')->with(['photos:id,uuid,observation_id'])->orderByDesc('observed_on')->orderByDesc('id')->limit(300)->get();
        $fields = FarmField::query()->whereIn('id', $obs->pluck('field_id')->filter()->unique())->pluck('code', 'id');
        $plans = CropPlan::query()->whereIn('id', $obs->pluck('crop_plan_id')->filter()->unique())->pluck('code', 'id');
        $people = User::withoutGlobalScopes()->whereIn('id', $obs->pluck('responsible_user_id')->merge($obs->pluck('reviewed_by'))->filter()->unique())->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->name]);

        return [
            'observations' => $obs->map(fn (CropObservation $o) => ['id' => $o->uuid, 'observed_on' => $o->observed_on->toDateString(), 'kind' => $o->kind,
                'severity' => $o->severity, 'field' => $fields[$o->field_id] ?? null, 'plan' => $plans[$o->crop_plan_id] ?? null, 'description' => $o->description,
                'growth_stage' => $o->growth_stage, 'affected_area_ha' => $o->affected_area_ha, 'incidence_pct' => $o->incidence_pct,
                'recommended_action' => $o->recommended_action, 'responsible' => $people[$o->responsible_user_id] ?? null,
                'follow_up_on' => $o->follow_up_on?->toDateString(), 'overdue' => $o->follow_up_on && $o->follow_up_on->lt(today()) && $o->status === 'open',
                'action_required' => $o->action_required, 'status' => $o->status, 'escalated' => (bool) $o->escalated_at, 'treated' => (bool) $o->treatment_activity_id,
                'ai' => $o->ai_suggestion, 'reviewed_by' => $people[$o->reviewed_by] ?? null, 'review_note' => $o->review_note,
                'gps' => $o->latitude !== null ? ['lat' => (float) $o->latitude, 'lng' => (float) $o->longitude] : null,
                'photos' => $o->photos->map(fn ($p) => $p->uuid)->all(),
                'treatable' => FieldActivity::query()->where('farm_id', $o->farm_id)->when($o->crop_plan_id, fn ($q) => $q->where('crop_plan_id', $o->crop_plan_id))
                    ->whereDate('planned_on', '>=', $o->observed_on)->orderBy('planned_on')->limit(10)->get(['uuid', 'title', 'planned_on'])
                    ->map(fn ($a) => ['id' => $a->uuid, 'title' => $a->title.' ('.$a->planned_on->toDateString().')'])->all()]),
            'thresholds' => ScoutingThreshold::query()->orderBy('kind')->get(),
        ];
    }

    private function irrigationData($farm): array
    {
        $balance = app(WaterBalance::class);
        foreach ($farm ? [$farm] : Farm::query()->operational()->get() as $f) {
            try {
                $balance->computeIfStale($f);
            } catch (\Throwable $e) {
                report($e);    // the screen still shows what was recorded
            }
        }

        $zones = IrrigationZone::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->with('field')->orderBy('code')->get();
        $events = IrrigationEvent::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->where(fn ($q) => $q->where('status', 'planned')->orWhere('actual_start', '>=', now()->subDays(30)))->with('zone')->orderByDesc('planned_start')->limit(200)->get();
        $fields = FarmField::query()->whereIn('id', $events->pluck('field_id')->merge($zones->pluck('field_id'))->unique())->pluck('code', 'id');
        $moisture = SoilMoistureReading::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->where('read_at', '>=', now()->subDays(30))
            ->orderByDesc('read_at')->get()->groupBy('field_id');

        return [
            'zones' => $zones->map(fn ($z) => ['id' => $z->uuid, 'code' => $z->code, 'name' => $z->name, 'field' => $z->field?->code, 'field_id' => $z->field?->uuid,
                'farm_id' => $z->farm?->uuid, 'system' => $z->system, 'area_ha' => $z->area_ha, 'flow_m3_per_hour' => $z->flow_m3_per_hour,
                'water_cost_per_m3' => $z->water_cost_per_m3, 'energy_kwh_per_hour' => $z->energy_kwh_per_hour, 'energy_cost_per_kwh' => $z->energy_cost_per_kwh,
                'pump' => $z->pump_machine_id ? FarmMachine::query()->whereKey($z->pump_machine_id)->value('fleet_number') : null, 'meter_ref' => $z->meter_ref]),
            'events' => $events->map(fn ($e) => ['id' => $e->uuid, 'field' => $fields[$e->field_id] ?? null, 'zone' => $e->zone?->code, 'status' => $e->status,
                'planned_start' => $e->planned_start?->format('Y-m-d H:i'), 'planned_minutes' => $e->planned_minutes, 'planned_m3' => $e->planned_m3,
                'actual_start' => $e->actual_start?->format('Y-m-d H:i'), 'actual_minutes' => $e->actual_minutes, 'actual_m3' => $e->actual_m3,
                'cost' => round((float) $e->water_cost + (float) $e->energy_cost, 2) ?: null, 'source' => $e->source, 'notes' => $e->notes]),
            'moisture' => $moisture->map(fn ($rows, $fid) => ['field' => $fields[$fid] ?? FarmField::query()->whereKey($fid)->value('code'),
                'latest' => (float) $rows->first()->vwc_pct, 'at' => $rows->first()->read_at->format('Y-m-d H:i'),
                'series' => $rows->take(14)->reverse()->map(fn ($r) => ['at' => $r->read_at->format('d M'), 'vwc' => (float) $r->vwc_pct])->values()])->values(),
            'exceptions' => IrrigationException::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->whereNull('resolved_at')->orderByDesc('id')->get()
                ->map(fn ($x) => ['id' => $x->uuid, 'kind' => $x->kind, 'note' => $x->note, 'field' => $x->field_id ? FarmField::query()->whereKey($x->field_id)->value('code') : null,
                    'at' => $x->created_at->format('Y-m-d H:i')]),
            'balance' => $balance->screen($farm),
            'pumps' => FarmMachine::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->whereIn('vehicle_type', ['pump', 'irrigation'])
                ->get(['uuid', 'code', 'name', 'farm_id'])->map(fn ($m) => ['id' => $m->uuid, 'name' => "{$m->code} {$m->name}"]),
        ];
    }

    private function harvestData($plans): array
    {
        $h = app(HarvestPlanning::class);
        $live = $plans->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING]);

        return [
            'plans' => $live->map(fn (CropPlan $p) => ['id' => $p->uuid, 'code' => $p->code, 'crop' => $p->cropType?->name, 'variety' => $p->variety?->name,
                'field' => $p->field?->code, 'status' => $p->status, 'unit' => $p->yield_unit, 'hectares' => round($p->hectares(), 2),
                'stage' => $p->current_stage, 'planned_harvest_on' => $p->planned_harvest_on?->toDateString()] + $h->overview($p))->values(),
            'commitments' => HarvestCommitment::query()->whereIn('farm_id', $plans->pluck('farm_id')->unique())->whereIn('status', ['open', 'short'])
                ->orderBy('deliver_on')->get()->map(fn ($c) => $h->commitmentRow($c)),
            'harvests' => HarvestRecord::query()->whereIn('crop_plan_id', $live->pluck('id'))->whereNull('commitment_id')->orderByDesc('harvested_on')->limit(100)->get()
                ->map(fn ($r) => ['id' => $r->uuid, 'label' => "{$r->lot_code} · {$r->quantity} {$r->unit}".($r->grade ? " · {$r->grade}" : '')]),
        ];
    }

    private function inputsData(Request $request, $plans): array
    {
        $planning = app(InputPlanning::class);
        $live = $plans->whereIn('status', CropPlan::LIVE);

        return [
            'templates' => CropInputTemplate::query()->with([])->orderBy('crop_type_id')->orderBy('timing_days')->get()->map(fn ($t) => $t->toArray() + [
                'crop' => CropType::query()->whereKey($t->crop_type_id)->value('name'),
                'item' => $t->stock_item_id ? FinStockItem::withoutGlobalScopes()->whereKey($t->stock_item_id)->value('name') : null,
                'mine' => (int) $t->created_by === (int) $request->user()->id]),
            'buying' => $planning->forPlans($live),
            'plans' => $live->map(fn ($p) => ['code' => $p->code, 'id' => $p->uuid] + $planning->forPlan($p)['totals'])->values(),
            'stock_items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'uom'])
                ->map(fn ($i) => ['value' => $i->id, 'title' => "{$i->name} ({$i->code})"]),
            'categories' => CropInputTemplate::CATEGORIES,
        ];
    }

    private function profitData(Request $request, $plans): array
    {
        $p = app(CropProfitability::class);
        $by = in_array($request->query('by'), ['variety', 'field', 'season', 'method', 'crop'], true) ? $request->query('by') : 'variety';
        $chosen = $plans->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING, CropPlan::COMPLETED]);

        return [
            'plans' => $chosen->map(fn ($plan) => $p->forPlan($plan))->values(),
            'compare' => $p->compare($chosen, $by),
            'overheads' => OverheadRule::query()->orderByDesc('valid_from')->get(),
        ];
    }
}
