<?php

namespace App\Modules\Agri\Processes;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\SoilTest;
use App\Modules\Agri\Models\WeatherReading;
use App\Modules\Agri\Services\CropPlanService;
use App\Modules\Agri\Services\FieldWork;
use App\Modules\Agri\Services\Harvests;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Support\ActionCatalog;
use App\Modules\Workflow\Support\ConditionChecks;
use App\Modules\Workflow\Support\TemplateLibrary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Process 1 — the crop production cycle, planting to harvest, on the central workflow engine.
 *
 * A season request (started by the farm manager, or on the season's planning date) runs one field
 * season per crop field, side by side. Each field season:
 *   soil test → wait for the lab → lime first if the pH is low → choose the crop against the rotation
 *   rule (never the same crop three seasons running) → crop plan approved → seed, fertiliser,
 *   chemicals and fuel bought in parallel, each through its own purchase approval → if they are not
 *   in by the planting window: delay, or switch to a shorter-season variety → wait for 25 mm of rain
 *   in 7 days (from the weather feed) until the window closes: then plant dry, change crop or fallow →
 *   plant → scouting every 14 days; a pest means an approved chemical, a certified operator and calm
 *   wind before spraying, and the spray's withholding period blocks harvest → maturity → grain
 *   moisture (over 14% maize: wait three days or dry it, at a cost) → withholding over → harvest over
 *   the weighbridge, yield per field → processing (process 3) or the silo.
 * Hail or drought on the field interrupts whatever is under way: damage assessment, an insurance
 * claim, and a replant decision while the replant window is open.
 *
 * Every step works through the farm's own records — soil tests, crop plans, field activities and
 * their stock issues, harvests — so nothing is kept twice.
 */
class CropSeasonProcess
{
    public const CROPS = ['Maize' => 'MAIZE-W', 'Soya' => 'SOY', 'Wheat' => 'WHEAT'];

    public static function register(): void
    {
        foreach ([self::inputPurchase(), self::insuranceClaim(), self::fieldSeason(), self::season()] as $t) {
            TemplateLibrary::register($t);
        }
        self::actions();
        self::checks();
        // A lab result entered on the soil test continues the field season waiting for it.
        SoilTest::updated(function (SoilTest $t) {
            if ($t->wasChanged('ph') && $t->ph !== null) {
                app(WorkflowRunner::class)->signal((int) $t->company_id, 'agri.soil_test.result', ['soil_test_id' => $t->id, 'ph' => (float) $t->ph,
                    'p_mg_kg' => $t->p_mg_kg, 'k_mg_kg' => $t->k_mg_kg, 'recommendation' => $t->recommendation]);
            }
        });
    }

    /** Hail or drought on a field: every field season running on it stops and assesses the damage. */
    public static function reportDamage(FarmField $field, string $kind, ?string $notes, User $by): array
    {
        if (! in_array($kind, ['hail', 'drought', 'flood', 'frost', 'fire'], true)) {
            throw new WorkflowException('Damage is hail, drought, flood, frost or fire.');
        }

        return app(WorkflowRunner::class)->signal((int) $field->company_id, 'agri.crop_damage', ['field_id' => $field->id, 'kind' => $kind,
            'notes' => $notes, 'reported_by' => $by->id, 'reported_on' => today()->toDateString()]);
    }

    /**
     * Seasons whose planning date has arrived (the configured lead before the season opens) start on
     * every operational farm that has crop fields — once each; the start is idempotent per farm and season.
     */
    public static function startDue(): int
    {
        $lead = (int) config('agri.processes.planning_lead_days', 30);
        $def = WorkflowDefinition::query()->where('key', 'agri_crop_season')->where('status', 'published')->first();
        if (! $def) {
            return 0;
        }
        $started = 0;
        $seasons = CropSeason::query()->where('status', CropSeason::OPEN)->whereDate('starts_on', '<=', today()->addDays($lead))->whereDate('starts_on', '>=', today()->subDays(7))->get();
        foreach ($seasons as $season) {
            foreach (Farm::query()->where('status', Farm::ACTIVE)->get() as $farm) {
                $open = FarmField::query()->where('farm_id', $farm->id)->where('status', 'active')->where('land_use_type', 'field')->exists();
                $done = WorkflowInstance::query()->where('dedupe_key', "agri-season:{$farm->id}:{$season->id}")->exists();
                // The farm's manager runs the season, so the process owner can approve its steps.
                $by = User::query()->find(\App\Modules\Agri\Models\FarmMembership::withoutGlobalScopes()->where('farm_id', $farm->id)->where('role', 'manager')
                    ->where('status', 'active')->where('user_id', '!=', $def->process_owner_id)->orderBy('id')->value('user_id') ?? $def->process_owner_id ?? $def->created_by);
                if ($open && ! $done && $by) {
                    self::startSeason($farm, $season, $by);
                    $started++;
                }
            }
        }

        return $started;
    }

    public const KEYS = ['agri_input_purchase', 'agri_insurance_claim', 'agri_field_season', 'agri_crop_season'];

    /**
     * Install and publish the four processes for an organisation, children first. Where the company
     * has a second person review each version, install stops at the first one waiting for review;
     * running it again after the review carries on from there.
     */
    public static function install(User $owner): array
    {
        $defs = app(\App\Modules\Workflow\Services\DefinitionService::class);
        $out = [];
        auth()->setUser($owner);
        foreach (self::KEYS as $key) {
            $d = WorkflowDefinition::query()->where('key', $key)->first() ?? $defs->installTemplate($key);
            if ($d->status === 'draft' || ($d->status !== 'published' && $d->status !== 'in_review' && $defs->workingVersion($d)->status === 'draft')) {
                $defs->submit($d, $owner, 'Installed: '.$d->name);
            }
            $out[$key] = $d->fresh()->status;
            if ($out[$key] !== 'published') {
                break; // what starts it cannot be published before it is
            }
        }

        return $out;
    }

    /** Start a season for a farm: one field season per crop field. */
    public static function startSeason(Farm $farm, CropSeason $season, User $by, array $options = []): WorkflowInstance
    {
        $def = WorkflowDefinition::query()->where('key', 'agri_crop_season')->where('status', 'published')->first()
            ?? throw new WorkflowException('The crop season process is not installed and published for this organisation.');
        $start = Carbon::parse($options['planting_window_start'] ?? $season->starts_on);

        return app(WorkflowRunner::class)->start($def, [
            'farm_id' => $farm->id, 'farm_name' => $farm->name, 'season_id' => $season->id, 'season_name' => $season->name,
            'planting_window_start' => $start->toDateString(),
            'planting_window_end' => Carbon::parse($options['planting_window_end'] ?? $start->copy()->addDays(45))->toDateString(),
            'replant_window_end' => Carbon::parse($options['replant_window_end'] ?? $start->copy()->addDays(75))->toDateString(),
            'rain_threshold_mm' => (float) ($options['rain_threshold_mm'] ?? 25), 'laboratory' => $options['laboratory'] ?? 'Soil laboratory',
            'only_fields' => $options['field_ids'] ?? null,
        ], $by->id, [], "agri-season:{$farm->id}:{$season->id}", null, "Crop season {$season->name} — {$farm->name}");
    }

    // ── actions ──────────────────────────────────────────────────────────────────────────────

    private static function actions(): void
    {
        $plan = fn (WorkflowInstance $i) => CropPlan::withoutGlobalScopes()->findOrFail($i->data['crop_plan_id'] ?? 0);
        $field = fn (WorkflowInstance $i) => FarmField::withoutGlobalScopes()->findOrFail($i->data['field_id'] ?? 0);
        $actor = fn (WorkflowInstance $i) => self::actor($i);

        ActionCatalog::register('agri.season_fields', ['label' => 'Farm: list the crop fields for the season', 'module' => 'agri'], function (WorkflowInstance $i) {
            $only = array_filter((array) ($i->data['only_fields'] ?? []));
            $fields = FarmField::withoutGlobalScopes()->where('farm_id', $i->data['farm_id'])->where('status', 'active')->where('land_use_type', 'field')
                ->when($only, fn ($q) => $q->whereIn('id', $only))->orderBy('code')->get()
                ->map(fn ($f) => ['field_id' => $f->id, 'field_code' => $f->code, 'name' => "{$f->code} {$f->name}", 'area_ha' => (float) $f->area])->values()->all();
            if (! $fields) {
                throw new WorkflowException('The farm has no active crop fields to plan.');
            }

            return ['data' => ['fields' => $fields], 'fields' => count($fields)];
        });

        ActionCatalog::register('agri.request_soil_test', ['label' => 'Farm: send a soil sample to the laboratory', 'module' => 'agri'], function (WorkflowInstance $i) use ($field, $actor) {
            $f = $field($i);
            if (! empty($i->data['soil_test_id'])) {
                return ['soil_test_id' => $i->data['soil_test_id']];
            }
            $t = SoilTest::withoutGlobalScopes()->create(['company_id' => $f->company_id, 'farm_id' => $f->farm_id, 'field_id' => $f->id, 'sampled_on' => today(),
                'laboratory' => $i->data['laboratory'] ?? 'Soil laboratory', 'sample_ref' => 'WF-'.$f->code.'-'.today()->format('ymd'), 'recorded_by' => $actor($i)->id]);

            return ['data' => ['soil_test_id' => $t->id, 'soil_test_ref' => $t->sample_ref], 'soil_test' => $t->uuid];
        });

        ActionCatalog::register('agri.rotation_check', ['label' => 'Farm: check the field\'s crop rotation', 'module' => 'agri'], function (WorkflowInstance $i) use ($field) {
            $history = self::history($field($i), (int) $i->data['season_id']);
            $blocked = count($history) >= 2 && $history[0] === $history[1] ? $history[0] : null;
            $options = array_values(array_filter(array_keys(self::CROPS), fn ($label) => self::CROPS[$label] !== $blocked));

            return ['data' => ['rotation_history' => $history, 'rotation_blocked' => $blocked ? array_search($blocked, self::CROPS, true) ?: $blocked : null,
                'crop_options' => $options], 'history' => $history];
        });

        ActionCatalog::register('agri.validate_crop', ['label' => 'Farm: check the chosen crop against the rotation rule', 'module' => 'agri'], function (WorkflowInstance $i) {
            $code = self::CROPS[$i->data['crop'] ?? ''] ?? null;
            $history = (array) ($i->data['rotation_history'] ?? []);
            $ok = $code && ! (count($history) >= 2 && $history[0] === $code && $history[1] === $code);

            return ['data' => ['crop_code' => $code, 'rotation_ok' => $ok,
                'rotation_note' => $ok ? null : "{$i->data['crop']} was planted on this field in each of the last two seasons; a third season in a row is not allowed."]];
        });

        ActionCatalog::register('agri.create_crop_plan', ['label' => 'Farm: draft the crop plan', 'module' => 'agri'], function (WorkflowInstance $i) use ($field, $actor) {
            $f = $field($i);
            $type = CropType::withoutGlobalScopes()->where('company_id', $f->company_id)->where('code', $i->data['crop_code'])->firstOrFail();
            $variety = self::variety($type, $i->data['variety'] ?? null, (bool) ($i->data['short_season'] ?? false));
            $days = $variety?->days_to_maturity ?: 140;
            $season = CropSeason::withoutGlobalScopes()->findOrFail($i->data['season_id']);
            $plantOn = Carbon::parse($i->data['planting_window_start'])->max($season->starts_on);
            if (! empty($i->data['crop_plan_id']) && ($existing = CropPlan::withoutGlobalScopes()->find($i->data['crop_plan_id'])) && $existing->status !== CropPlan::CANCELLED) {
                $existing->update(['crop_type_id' => $type->id, 'variety_id' => $variety?->id, 'status' => CropPlan::DRAFT, 'approved_by' => null, 'approved_at' => null]);

                return ['data' => ['crop_plan_code' => $existing->code, 'days_to_maturity' => $days], 'crop_plan' => $existing->uuid];
            }
            $p = app(CropPlanService::class)->create(Farm::withoutGlobalScopes()->findOrFail($f->farm_id), $f, ['season_id' => $season->id, 'crop_type_id' => $type->id,
                'variety_id' => $variety?->id, 'code' => strtoupper($type->code).'-'.$season->code.'-'.$f->code, 'planned_area' => (float) $f->area ?: 1, 'area_unit' => 'ha',
                'planned_planting_on' => $plantOn->toDateString(), 'planned_harvest_on' => $plantOn->copy()->addDays($days)->min($season->ends_on)->max($plantOn->copy()->addDay())->toDateString(),
                'notes' => "Planned by {$i->reference}"], $actor($i));

            return ['data' => ['crop_plan_id' => $p->id, 'crop_plan_code' => $p->code, 'variety_name' => $variety?->name, 'days_to_maturity' => $days], 'crop_plan' => $p->uuid];
        });

        ActionCatalog::register('agri.approve_crop_plan', ['label' => 'Farm: mark the crop plan approved', 'module' => 'agri'], function (WorkflowInstance $i) use ($plan) {
            $p = $plan($i);
            $by = \App\Modules\Workflow\Models\WorkflowTask::query()->where('instance_id', $i->id)->where('node_key', 'plan_ok')->where('decision', 'approved')->latest('completed_at')->value('decided_by');
            if ($p->status === CropPlan::DRAFT) {
                $p->update(['status' => CropPlan::APPROVED, 'approved_by' => $by, 'approved_at' => now()]);
            }

            return ['status' => $p->fresh()->status];
        });

        ActionCatalog::register('agri.input_list', ['label' => 'Farm: list the inputs to buy', 'module' => 'agri'], fn (WorkflowInstance $i) => ['data' => ['inputs' => [
            ['kind' => 'seed', 'label' => 'Seed'], ['kind' => 'fertiliser', 'label' => 'Fertiliser'], ['kind' => 'chemicals', 'label' => 'Chemicals'], ['kind' => 'fuel', 'label' => 'Fuel']]]]);

        ActionCatalog::register('agri.set_planting_window', ['label' => 'Farm: move the planting window', 'module' => 'agri'], function (WorkflowInstance $i) {
            $start = Carbon::parse($i->data['new_planting_start'] ?? today()->addWeek());
            $len = Carbon::parse($i->data['planting_window_start'])->diffInDays(Carbon::parse($i->data['planting_window_end']));

            return ['data' => ['planting_window_start' => $start->toDateString(), 'planting_window_end' => $start->copy()->addDays(max(7, $len))->toDateString(), 'planting_delayed' => true]];
        });

        ActionCatalog::register('agri.switch_variety', ['label' => 'Farm: switch to the shortest-season variety', 'module' => 'agri'], function (WorkflowInstance $i) use ($plan) {
            $p = $plan($i);
            $v = self::variety(CropType::withoutGlobalScopes()->findOrFail($p->crop_type_id), null, true);
            if ($v) {
                $p->update(['variety_id' => $v->id]);
            }

            return ['data' => ['short_season' => true, 'variety_name' => $v?->name, 'days_to_maturity' => $v?->days_to_maturity ?: ($i->data['days_to_maturity'] ?? 120)]];
        });

        ActionCatalog::register('agri.mark_fallow', ['label' => 'Farm: leave the field fallow', 'module' => 'agri'], function (WorkflowInstance $i) {
            if (! empty($i->data['crop_plan_id']) && ($p = CropPlan::withoutGlobalScopes()->find($i->data['crop_plan_id'])) && in_array($p->status, [CropPlan::DRAFT, CropPlan::APPROVED], true)) {
                $p->update(['status' => CropPlan::CANCELLED, 'cancel_reason' => 'Left fallow: the planting window closed without rain.']);
            }

            return ['data' => ['fallow' => true]];
        });

        ActionCatalog::register('agri.plant', ['label' => 'Farm: record planting', 'module' => 'agri'], function (WorkflowInstance $i) use ($plan) {
            $p = $plan($i);
            $on = Carbon::parse($i->data['planted_on'] ?? today())->min(today());
            if ($p->status === CropPlan::APPROVED) {
                app(CropPlanService::class)->plant($p, $on->toDateString());
            } else {
                $p->update(['planted_on' => $on->toDateString(), 'notes' => trim(($p->notes ?? '')."\nReplanted {$on->toDateString()}.")]); // a replant after damage
            }
            $days = (int) ($i->data['days_to_maturity'] ?? 140);

            return ['data' => ['planted_on' => $on->toDateString(), 'harvest_from' => $on->copy()->addDays($days)->toDateString(), 'harvested' => false]];
        });

        ActionCatalog::register('agri.check_operator', ['label' => 'Farm: check the spray operator is certified', 'module' => 'agri'], function (WorkflowInstance $i) {
            $code = config('agri.processes.spray_competency', 'PESTICIDE-APPLICATOR');
            $person = DB::table('hr_people')->where('user_id', (int) ($i->data['operator'] ?? 0))->value('id');
            $ok = $person && DB::table('sheq_competency_records as r')->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
                ->where('r.company_id', $i->company_id)->where('r.hr_person_id', $person)->where('c.code', $code)->whereIn('r.status', ['COMPLIANT', 'EXPIRING', 'EXEMPT'])->whereNull('r.revoked_at')->whereNull('r.deleted_at')
                ->where(fn ($q) => $q->whereNull('r.expires_at')->orWhereDate('r.expires_at', '>=', today()))->exists();

            return ['data' => ['operator_certified' => $ok, 'operator_note' => $ok ? null : "The operator holds no valid {$code} certificate."]];
        });

        ActionCatalog::register('agri.record_spray', ['label' => 'Farm: record the spray and its withholding period', 'module' => 'agri'], function (WorkflowInstance $i) use ($plan, $actor) {
            $p = $plan($i);
            $item = FinStockItem::withoutGlobalScopes()->where('company_id', $i->company_id)->where('code', $i->data['chemical_code'] ?? '')->first()
                ?? throw new WorkflowException("No stock item with the code {$i->data['chemical_code']}.");
            $work = app(FieldWork::class);
            $farm = Farm::withoutGlobalScopes()->findOrFail($p->farm_id);
            $a = $work->create($farm, null, $p, null, ['activity_type' => 'spraying', 'title' => "Spray {$item->name} ({$i->reference})", 'planned_on' => today()->toDateString(),
                'assigned_to' => $i->data['operator'] ?? null], $actor($i));
            $work->addInput($a, $item, ['quantity' => (float) $i->data['chemical_quantity'], 'withholding_days' => (int) ($i->data['withholding_days'] ?? 0)]);
            $work->complete($a, ['completed_at' => now()->toDateTimeString()], $actor($i));
            $until = $work->withholdingUntil($p->fresh());

            return ['data' => ['withholding_until' => $until?->toDateString(), 'last_spray_on' => today()->toDateString()], 'activity' => $a->uuid];
        });

        ActionCatalog::register('agri.read_moisture', ['label' => 'Farm: read grain moisture from the sensor', 'module' => 'agri'], function (WorkflowInstance $i) {
            $reading = DB::table('prd_readings as r')->join('prd_devices as d', 'd.id', '=', 'r.device_id')->join('prd_units as u', 'u.id', '=', 'd.unit_id')
                ->where('u.field_id', $i->data['field_id'])->where('r.metric', 'grain_moisture')->where('r.measured_at', '>=', now()->subDay())
                ->orderByDesc('r.measured_at')->value('r.value');

            return ['data' => $reading !== null ? ['moisture_pct' => (float) $reading, 'moisture_source' => 'sensor'] : ['moisture_pct' => null]];
        });

        ActionCatalog::register('agri.record_drying', ['label' => 'Farm: record drying and its cost', 'module' => 'agri'], function (WorkflowInstance $i) use ($plan, $actor) {
            $p = $plan($i);
            $a = app(FieldWork::class)->create(Farm::withoutGlobalScopes()->findOrFail($p->farm_id), null, $p, null, ['activity_type' => 'other',
                'title' => "Grain drying ({$i->reference})", 'planned_on' => today()->toDateString(), 'labour_cost' => (float) ($i->data['drying_cost'] ?? 0),
                'notes' => 'Moisture '.($i->data['moisture_pct'] ?? '?').'% — sent to the dryer.'], $actor($i));

            return ['data' => ['dried' => true], 'activity' => $a->uuid];
        });

        ActionCatalog::register('agri.record_harvest', ['label' => 'Farm: record the harvest over the weighbridge', 'module' => 'agri'], function (WorkflowInstance $i) use ($plan, $actor) {
            $p = $plan($i);
            $silo = ($i->data['destination'] ?? 'Silo') === 'Silo';
            $item = FinStockItem::withoutGlobalScopes()->where('company_id', $i->company_id)->where('code', $i->data['stock_code'] ?? '')->first();
            $h = app(Harvests::class)->record($p, ['harvested_on' => today()->toDateString(), 'quantity' => (float) $i->data['quantity_t'], 'unit' => 't',
                'measuring_method' => 'weighbridge', 'moisture_pct' => $i->data['moisture_pct'] ?? null, 'destination' => $item ? 'store' : 'sold',
                'stock_item_id' => $item?->id, 'store_id' => $i->data['store'] ?? null, 'storage_location' => $silo ? 'Silo' : 'Processing intake',
                'unit_value' => (float) ($i->data['unit_value'] ?? 0)], $actor($i));
            $area = (float) ($i->data['area_ha'] ?? $p->planned_area) ?: 1;

            return ['data' => ['harvested' => true, 'harvest_record_id' => $h->id, 'harvest_lot' => $h->lot_code, 'yield_t_per_ha' => round((float) $i->data['quantity_t'] / $area, 2)],
                'harvest' => $h->uuid];
        });

        ActionCatalog::register('agri.start_processing', ['label' => 'Farm: hand the harvest to processing (process 3)', 'module' => 'agri'], function (WorkflowInstance $i) {
            $next = WorkflowDefinition::query()->where('key', config('agri.processes.processing_key', 'agri_processing'))->where('status', 'published')->first();
            if (! $next) {
                return ['data' => ['processing_request' => null, 'processing_note' => 'The processing process is not published yet; the harvest lot is in store for processing.']];
            }
            $child = app(WorkflowRunner::class)->start($next, collect($i->data)->only(['farm_id', 'field_id', 'crop_code', 'harvest_lot', 'harvest_record_id', 'quantity_t'])->all(),
                $i->requester_id, [], "agri-processing:{$i->uuid}", null, "Processing {$i->data['harvest_lot']}");

            return ['data' => ['processing_request' => $child->reference]];
        });

        ActionCatalog::register('agri.record_damage', ['label' => 'Farm: record crop damage on the field', 'module' => 'agri'], function (WorkflowInstance $i) use ($field, $actor) {
            $f = $field($i);
            $o = \App\Modules\Agri\Models\CropObservation::withoutGlobalScopes()->create(['company_id' => $f->company_id, 'farm_id' => $f->farm_id, 'field_id' => $f->id,
                'crop_plan_id' => $i->data['crop_plan_id'] ?? null, 'observed_on' => today(), 'kind' => 'weather_damage', 'severity' => ((float) ($i->data['damage_pct'] ?? 0)) >= 50 ? 'high' : 'medium',
                'description' => ucfirst($i->data['damage']['kind'] ?? 'weather').' damage: '.($i->data['damage_pct'] ?? '?').'% — '.($i->data['damage_notes'] ?? ''), 'recorded_by' => $actor($i)->id]);

            return ['data' => ['damage_observation_id' => $o->id], 'observation' => $o->uuid];
        });
    }

    // ── checks ───────────────────────────────────────────────────────────────────────────────

    private static function checks(): void
    {
        // Rain from the weather feed (observed days) and any rain gauge on the field, over the last N days.
        ConditionChecks::register('agri.rainfall', 'Farm: rainfall reached a threshold', [['key' => 'mm', 'label' => 'Millimetres', 'type' => 'number'], ['key' => 'days', 'label' => 'Over days', 'type' => 'number']],
            function (WorkflowInstance $i, array $p) {
                $days = max(1, (int) ($p['days'] ?? 7));
                $since = now()->subDays($days);
                $feed = (float) WeatherReading::withoutGlobalScopes()->where('farm_id', $i->data['farm_id'] ?? 0)->whereIn('kind', ['observed'])->where('period_start', '>=', $since)->sum('rain_mm');
                $gauge = (float) DB::table('prd_readings as r')->join('prd_devices as d', 'd.id', '=', 'r.device_id')->join('prd_units as u', 'u.id', '=', 'd.unit_id')
                    ->where('u.field_id', $i->data['field_id'] ?? 0)->where('r.metric', 'rain')->where('r.measured_at', '>=', $since)->sum('r.value');
                $mm = max($feed, $gauge);

                return ['met' => $mm >= (float) ($p['mm'] ?? 25), 'value' => round($mm, 1).' mm in '.$days.' days', 'note' => $gauge > $feed ? 'From the field rain gauge.' : 'From the weather feed.'];
            });

        // Wind for spraying: the latest reading in the last three hours, from the field's station or the weather feed.
        ConditionChecks::register('agri.wind_ok', 'Farm: wind is calm enough to spray', [['key' => 'max_kmh', 'label' => 'Highest wind (km/h)', 'type' => 'number']],
            function (WorkflowInstance $i, array $p) {
                $station = DB::table('prd_readings as r')->join('prd_devices as d', 'd.id', '=', 'r.device_id')->join('prd_units as u', 'u.id', '=', 'd.unit_id')
                    ->where('u.field_id', $i->data['field_id'] ?? 0)->where('r.metric', 'wind_speed')->where('r.measured_at', '>=', now()->subHours(3))->orderByDesc('r.measured_at')->value('r.value');
                $feed = WeatherReading::withoutGlobalScopes()->where('farm_id', $i->data['farm_id'] ?? 0)->whereIn('kind', ['current', 'observed'])
                    ->where('period_start', '>=', now()->subHours(3))->orderByDesc('period_start')->value('wind_speed');
                $wind = $station ?? $feed;
                if ($wind === null) {
                    return ['met' => false, 'value' => null, 'note' => 'No recent wind reading.'];
                }

                return ['met' => (float) $wind <= (float) ($p['max_kmh'] ?? 15), 'value' => round((float) $wind, 1).' km/h'];
            });

        // The farm's own harvest rule: no harvest inside the latest spray's withholding period.
        ConditionChecks::register('agri.harvest_allowed', 'Farm: no chemical withholding period is running', [],
            function (WorkflowInstance $i) {
                $p = CropPlan::withoutGlobalScopes()->find($i->data['crop_plan_id'] ?? 0);
                $until = $p ? app(FieldWork::class)->withholdingUntil($p) : null;

                return ['met' => ! $until || ! $until->isAfter(today()), 'value' => $until?->toDateString(), 'note' => $until && $until->isAfter(today()) ? 'Withholding until '.$until->toDateString().'.' : null];
            });
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** Crop codes planted on the field in the seasons before this one, most recent first. */
    public static function history(FarmField $field, int $seasonId): array
    {
        $season = CropSeason::withoutGlobalScopes()->find($seasonId);

        return CropPlan::withoutGlobalScopes()->where('crop_plans.field_id', $field->id)->where('crop_plans.status', '!=', CropPlan::CANCELLED)
            ->join('crop_seasons as s', 's.id', '=', 'crop_plans.season_id')->join('crop_types as t', 't.id', '=', 'crop_plans.crop_type_id')
            ->when($season, fn ($q) => $q->where('s.starts_on', '<', $season->starts_on))
            ->orderByDesc('s.starts_on')->limit(2)->pluck('t.code')
            ->map(fn ($c) => str_starts_with($c, 'MAIZE') ? 'MAIZE-W' : $c)->all(); // white and yellow maize are one crop for rotation
    }

    private static function variety(CropType $type, ?string $name, bool $shortest): ?CropVariety
    {
        $q = CropVariety::withoutGlobalScopes()->where('crop_type_id', $type->id)->where('is_active', true);
        if ($name && ($v = (clone $q)->where('name', $name)->first())) {
            return $v;
        }

        return $shortest ? $q->orderBy('days_to_maturity')->first() : $q->orderByDesc('days_to_maturity')->first();
    }

    /** Who a step acts as: the last person who decided a task in the request, else whoever started it. */
    private static function actor(WorkflowInstance $i): User
    {
        $id = \App\Modules\Workflow\Models\WorkflowTask::query()->where('instance_id', $i->id)->whereNotNull('decided_by')->latest('completed_at')->value('decided_by')
            ?? $i->requester_id ?? WorkflowInstance::query()->whereKey($i->parent_instance_id)->value('requester_id');

        return User::query()->findOrFail($id);
    }

    // ── templates ────────────────────────────────────────────────────────────────────────────

    private static function n(string $key, string $type, string $name, int $x, int $y, array $config = []): array
    {
        return compact('key', 'type', 'name', 'x', 'y', 'config');
    }

    private static function e(string $from, string $to, array $extra = []): array
    {
        static $i = 0;

        return array_merge(['key' => 'e'.(++$i), 'from' => $from, 'to' => $to], $extra);
    }

    private static function f(string $key, string $label, string $type, bool $required = false, array $extra = []): array
    {
        return array_merge(compact('key', 'label', 'type', 'required'), $extra);
    }

    private static function owner(): array
    {
        return ['type' => 'process_owner'];
    }

    private static function requester(): array
    {
        return ['type' => 'requester'];
    }

    public static function season(): array
    {
        return ['key' => 'agri_crop_season', 'name' => 'Crop season (all fields)', 'category' => 'Farm', 'module' => 'generic',
            'description' => 'Season planning for a farm: one field season per crop field, side by side, from soil test to harvest.',
            'trigger_type' => 'manual', 'trigger_config' => [],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Season planning starts', 40, 200),
                self::n('fields', 'action', 'List the crop fields', 240, 200, ['action' => 'agri.season_fields']),
                self::n('each', 'multi_instance', 'Each field: plant to harvest', 440, 200, ['definition_key' => 'agri_field_season', 'collection' => 'fields', 'item_as' => 'field',
                    'pass_data' => true, 'wait' => true, 'results_as' => 'field_results']),
                self::n('report', 'notification', 'Season finished', 640, 200, ['to' => self::requester(), 'subject' => 'Season {{season_name}} finished on {{farm_name}}',
                    'message' => 'Every field season on {{farm_name}} has finished.']),
                self::n('end', 'end', 'Season closed', 840, 200, ['outcome' => 'completed']),
            ], 'edges' => [self::e('start', 'fields'), self::e('fields', 'each'), self::e('each', 'report'), self::e('report', 'end')]]];
    }

    public static function fieldSeason(): array
    {
        $o = self::owner();
        $r = self::requester();
        $branch = ['outcome' => 'completed', 'scope' => 'branch'];

        return ['key' => 'agri_field_season', 'name' => 'Field season: soil test to harvest', 'category' => 'Farm', 'module' => 'generic',
            'description' => 'One field from soil test, rotation and inputs through rain-gated planting, scouting and spraying to the weighbridge — interrupted by hail or drought.',
            'trigger_type' => 'manual', 'trigger_config' => [],
            'forms' => [
                'lime' => ['name' => 'Lime application', 'fields' => [self::f('lime_t_per_ha', 'Lime applied (t/ha)', 'decimal', true), self::f('limed_on', 'Applied on', 'date', true)]],
                'crop' => ['name' => 'Crop choice', 'fields' => [self::f('crop', 'Crop', 'select', true, ['options' => array_keys(self::CROPS)]),
                    self::f('variety', 'Variety (optional)', 'text'), self::f('short_season', 'Use a shorter-season variety', 'yesno')]],
                'late' => ['name' => 'Inputs late', 'fields' => [self::f('late_choice', 'What to do', 'radio', true, ['options' => ['Delay planting', 'Switch to a shorter-season variety']]),
                    self::f('new_planting_start', 'New planting start', 'date', false, ['required_if' => ['field' => 'late_choice', 'operator' => 'eq', 'value' => 'Delay planting']])]],
                'window' => ['name' => 'Planting window closed', 'fields' => [self::f('window_choice', 'What to do', 'radio', true, ['options' => ['Plant dry', 'Change crop', 'Leave fallow']])]],
                'planting' => ['name' => 'Planting', 'fields' => [self::f('planted_on', 'Planted on', 'date', true), self::f('seed_rate', 'Seed rate (kg/ha)', 'decimal')]],
                'scouting' => ['name' => 'Scouting', 'fields' => [self::f('pest_found', 'Pest or disease found', 'yesno', true), self::f('pest', 'What was found', 'text'),
                    self::f('operator', 'Spray operator', 'user', false, ['required_if' => ['field' => 'pest_found', 'operator' => 'true']])]],
                'operator' => ['name' => 'Spray operator', 'fields' => [self::f('operator', 'Certified operator', 'user', true)]],
                'spray' => ['name' => 'Spray record', 'fields' => [self::f('chemical_code', 'Chemical (stock code)', 'text', true), self::f('chemical_quantity', 'Quantity used', 'decimal', true),
                    self::f('withholding_days', 'Withholding period (days)', 'number', true)]],
                'moisture' => ['name' => 'Moisture reading', 'fields' => [self::f('moisture_pct', 'Grain moisture (%)', 'decimal', true)]],
                'wet' => ['name' => 'Grain too wet', 'fields' => [self::f('wet_choice', 'What to do', 'radio', true, ['options' => ['Wait and recheck in 3 days', 'Send to the dryer']]),
                    self::f('drying_cost', 'Drying cost', 'currency', false, ['required_if' => ['field' => 'wet_choice', 'operator' => 'eq', 'value' => 'Send to the dryer']])]],
                'harvest' => ['name' => 'Weighbridge', 'fields' => [self::f('quantity_t', 'Net weight (t)', 'decimal', true),
                    self::f('destination', 'Send to', 'radio', true, ['options' => ['Processing', 'Silo']]), self::f('stock_code', 'Stock item (code)', 'text'), self::f('store', 'Store (silo)', 'text')]],
                'damage' => ['name' => 'Damage assessment', 'fields' => [self::f('damage_pct', 'Crop damaged (%)', 'decimal', true), self::f('damage_notes', 'What was seen', 'textarea', true)]],
                'replant' => ['name' => 'Replant decision', 'fields' => [self::f('replant_choice', 'Decision', 'radio', true, ['options' => ['Replant', 'Continue with the crop']])]],
            ],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Field season starts', 40, 300),
                self::n('soil_request', 'action', 'Send a soil sample to the lab', 200, 300, ['action' => 'agri.request_soil_test']),
                self::n('soil_wait', 'wait_event', 'Wait for the lab result', 360, 300, ['event' => 'agri.soil_test.result', 'correlate_field' => 'soil_test_id',
                    'correlate_key' => 'soil_test_id', 'store_as' => 'soil', 'timeout_hours' => 504]),
                self::n('soil_chase', 'user_task', 'Chase the lab for the soil result', 360, 460, ['assignee' => $r]),
                self::n('ph_check', 'decision', 'pH too low?', 520, 300),
                self::n('lime', 'user_task', 'Apply lime before planting', 520, 460, ['assignee' => $r, 'form' => 'lime']),
                self::n('rotation', 'action', 'Check the crop rotation', 680, 300, ['action' => 'agri.rotation_check']),
                self::n('choose', 'user_task', 'Choose the crop', 840, 300, ['assignee' => $r, 'form' => 'crop',
                    'instructions' => 'Crops allowed on this field: {{crop_options}}. Not {{rotation_blocked}} — it would be a third season in a row.']),
                self::n('validate', 'action', 'Check the choice against the rotation', 1000, 300, ['action' => 'agri.validate_crop']),
                self::n('rot_ok', 'decision', 'Rotation rule kept?', 1160, 300),
                self::n('rot_notice', 'notification', 'Rotation rule broken', 1160, 460, ['to' => $r, 'subject' => 'Choose another crop for {{field_code}}', 'message' => '{{rotation_note}}']),
                self::n('plan', 'action', 'Draft the crop plan', 1320, 300, ['action' => 'agri.create_crop_plan']),
                self::n('plan_ok', 'approval', 'Approve the crop plan', 1480, 300, ['assignee' => $o, 'mode' => 'any']),
                self::n('plan_record', 'action', 'Mark the plan approved', 1640, 300, ['action' => 'agri.approve_crop_plan']),
                self::n('inputs', 'action', 'List the inputs to buy', 1800, 300, ['action' => 'agri.input_list']),
                self::n('purchase', 'multi_instance', 'Buy seed, fertiliser, chemicals and fuel', 1960, 300, ['definition_key' => 'agri_input_purchase', 'collection' => 'inputs',
                    'item_as' => 'input', 'pass_data' => true, 'wait' => false]),
                self::n('inputs_wait', 'wait_condition', 'Inputs delivered before the planting window', 2120, 300, ['check' => 'children_completed',
                    'params' => ['definition_key' => 'agri_input_purchase'], 'deadline_field' => 'planting_window_start', 'poll_minutes' => 60]),
                self::n('late_notify', 'notification', 'Inputs are late', 2120, 460, ['to' => $r, 'subject' => 'Inputs for {{field_code}} are not in', 'message' => 'The planting window opens {{planting_window_start}} and not every input has been delivered.']),
                self::n('late_choice', 'user_task', 'Inputs late: delay planting or switch variety', 2120, 620, ['assignee' => $r, 'form' => 'late']),
                self::n('late_route', 'decision', 'Delay or switch?', 2280, 620),
                self::n('set_window', 'action', 'Move the planting window', 2280, 780, ['action' => 'agri.set_planting_window']),
                self::n('switch', 'action', 'Switch to a shorter-season variety', 2440, 620, ['action' => 'agri.switch_variety']),
                self::n('rain_wait', 'wait_condition', 'Wait for planting rain', 2600, 300, ['check' => 'agri.rainfall', 'params' => ['mm' => '{{rain_threshold_mm}}', 'days' => 7],
                    'deadline_field' => 'planting_window_end', 'poll_minutes' => 360]),
                self::n('window_choice', 'user_task', 'Planting window closed: plant dry, change crop or fallow', 2600, 460, ['assignee' => $r, 'form' => 'window']),
                self::n('window_route', 'decision', 'Plant dry, change or fallow?', 2600, 620),
                self::n('fallow', 'action', 'Leave the field fallow', 2600, 780, ['action' => 'agri.mark_fallow']),
                self::n('end_fallow', 'end', 'Field left fallow', 2600, 940, ['outcome' => 'completed']),
                self::n('plant', 'user_task', 'Plant the field', 2760, 300, ['assignee' => $r, 'form' => 'planting']),
                self::n('plant_record', 'action', 'Record planting', 2920, 300, ['action' => 'agri.plant']),
                self::n('scouting', 'recurring', 'Scout every 14 days', 3080, 300, ['every_days' => 14, 'node' => 'scout', 'stop_when' => ['field' => 'harvested', 'operator' => 'true']]),
                self::n('scout', 'user_task', 'Scout for pests and disease', 3080, 460, ['assignee' => $r, 'form' => 'scouting']),
                self::n('pest_route', 'decision', 'Pest found?', 3240, 460),
                self::n('scout_done', 'end', 'Nothing found', 3240, 620, $branch),
                self::n('spray_approval', 'approval', 'Approve the chemical', 3400, 460, ['assignee' => $o, 'mode' => 'any', 'distinct_approvers' => false]) /* its own decision, not a second level of the plan */,
                self::n('spray_refused', 'end', 'Chemical not approved', 3400, 620, $branch),
                self::n('operator_check', 'action', 'Check the operator is certified', 3560, 460, ['action' => 'agri.check_operator']),
                self::n('operator_route', 'decision', 'Operator certified?', 3720, 460),
                self::n('operator_fix', 'user_task', 'Assign a certified operator', 3720, 620, ['assignee' => $r, 'form' => 'operator']),
                self::n('wind_wait', 'wait_condition', 'Wait for calm wind', 3880, 460, ['check' => 'agri.wind_ok', 'params' => ['max_kmh' => 15], 'timeout_hours' => 72, 'poll_minutes' => 60]),
                self::n('wind_notice', 'notification', 'Too windy to spray', 3880, 620, ['to' => $r, 'subject' => 'Spraying {{field_code}} is waiting for calm wind', 'message' => 'Wind has been above 15 km/h for three days.']),
                self::n('spray', 'user_task', 'Spray', 4040, 460, ['assignee' => $r, 'form' => 'spray']),
                self::n('spray_record', 'action', 'Record the spray and its withholding period', 4200, 460, ['action' => 'agri.record_spray']),
                self::n('spray_done', 'end', 'Sprayed', 4360, 460, $branch),
                self::n('maturity', 'wait_condition', 'Wait for the crop to mature', 3240, 300, ['condition' => ['not' => ['field' => 'harvest_from', 'operator' => 'after', 'value' => 'today']], 'poll_minutes' => 1440]),
                self::n('moisture_read', 'action', 'Read grain moisture', 3400, 300, ['action' => 'agri.read_moisture']),
                self::n('moisture_known', 'decision', 'Moisture known?', 3560, 300),
                self::n('moisture_task', 'user_task', 'Take a moisture reading', 3560, 140, ['assignee' => $r, 'form' => 'moisture']),
                self::n('moisture_route', 'decision', 'Maize above 14%?', 3720, 300),
                self::n('wet_choice', 'user_task', 'Grain too wet: wait or dry?', 3720, 140, ['assignee' => $r, 'form' => 'wet']),
                self::n('wet_route', 'decision', 'Wait or dry?', 3880, 140),
                self::n('recheck', 'delay', 'Recheck in 3 days', 3880, 0, ['hours' => 72]),
                self::n('drying', 'action', 'Record drying and its cost', 4040, 140, ['action' => 'agri.record_drying']),
                self::n('withhold_wait', 'wait_condition', 'Withholding period over', 4040, 300, ['check' => 'agri.harvest_allowed', 'poll_minutes' => 1440]),
                self::n('harvest', 'user_task', 'Harvest and weigh at the weighbridge', 4200, 300, ['assignee' => $r, 'form' => 'harvest']),
                self::n('harvest_record', 'action', 'Record the yield', 4360, 300, ['action' => 'agri.record_harvest']),
                self::n('dest_route', 'decision', 'Processing or silo?', 4520, 300),
                self::n('to_processing', 'action', 'Hand to processing', 4680, 200, ['action' => 'agri.start_processing']),
                self::n('done', 'end', 'Harvested', 4840, 300, ['outcome' => 'completed']),
                // Hail or drought: assess, claim, replant or carry on.
                self::n('damage_assess', 'user_task', 'Assess the damage', 1960, 960, ['assignee' => $r, 'form' => 'damage']),
                self::n('damage_record', 'action', 'Record the damage', 2120, 960, ['action' => 'agri.record_damage']),
                self::n('claim', 'subworkflow', 'Open the insurance claim', 2280, 960, ['definition_key' => 'agri_insurance_claim', 'pass_data' => true, 'wait' => false]),
                self::n('replant_window', 'decision', 'Replant window still open?', 2440, 960),
                self::n('replant_choice', 'user_task', 'Replant or carry on?', 2600, 1100, ['assignee' => $r, 'form' => 'replant']),
                self::n('replant_route', 'decision', 'Replant?', 2760, 1100),
                self::n('resume', 'decision', 'Already planted?', 2760, 960),
            ], 'edges' => [
                self::e('start', 'soil_request'), self::e('soil_request', 'soil_wait'),
                self::e('soil_wait', 'ph_check', ['outcome' => 'received']), self::e('soil_wait', 'soil_chase', ['outcome' => 'timeout']), self::e('soil_chase', 'soil_wait'),
                self::e('ph_check', 'lime', ['condition' => ['field' => 'soil.ph', 'operator' => 'lt', 'value' => 5.5], 'label' => 'pH too low']),
                self::e('ph_check', 'rotation', ['is_default' => true, 'label' => 'pH fine']),
                self::e('lime', 'rotation'), self::e('rotation', 'choose'), self::e('choose', 'validate'), self::e('validate', 'rot_ok'),
                self::e('rot_ok', 'plan', ['condition' => ['field' => 'rotation_ok', 'operator' => 'true'], 'label' => 'Allowed']),
                self::e('rot_ok', 'rot_notice', ['is_default' => true, 'label' => 'Third season in a row']), self::e('rot_notice', 'choose'),
                self::e('plan', 'plan_ok'), self::e('plan_ok', 'plan_record', ['outcome' => 'approved']), self::e('plan_ok', 'choose', ['outcome' => 'rejected']),
                self::e('plan_record', 'inputs'), self::e('inputs', 'purchase'), self::e('purchase', 'inputs_wait'),
                self::e('inputs_wait', 'rain_wait', ['outcome' => 'met']), self::e('inputs_wait', 'late_notify', ['outcome' => 'timeout']),
                self::e('late_notify', 'late_choice'), self::e('late_choice', 'late_route'),
                self::e('late_route', 'set_window', ['condition' => ['field' => 'late_choice', 'operator' => 'eq', 'value' => 'Delay planting'], 'label' => 'Delay']),
                self::e('late_route', 'switch', ['is_default' => true, 'label' => 'Shorter-season variety']),
                self::e('set_window', 'inputs_wait'), self::e('switch', 'rain_wait'),
                self::e('rain_wait', 'plant', ['outcome' => 'met']), self::e('rain_wait', 'window_choice', ['outcome' => 'timeout']),
                self::e('window_choice', 'window_route'),
                self::e('window_route', 'plant', ['condition' => ['field' => 'window_choice', 'operator' => 'eq', 'value' => 'Plant dry'], 'label' => 'Plant dry']),
                self::e('window_route', 'choose', ['condition' => ['field' => 'window_choice', 'operator' => 'eq', 'value' => 'Change crop'], 'label' => 'Change crop']),
                self::e('window_route', 'fallow', ['is_default' => true, 'label' => 'Fallow']), self::e('fallow', 'end_fallow'),
                self::e('plant', 'plant_record'), self::e('plant_record', 'scouting'), self::e('scouting', 'maturity'),
                self::e('scout', 'pest_route'),
                self::e('pest_route', 'spray_approval', ['condition' => ['field' => 'pest_found', 'operator' => 'true'], 'label' => 'Pest found']),
                self::e('pest_route', 'scout_done', ['is_default' => true, 'label' => 'Clean']),
                self::e('spray_approval', 'operator_check', ['outcome' => 'approved']), self::e('spray_approval', 'spray_refused', ['outcome' => 'rejected']),
                self::e('operator_check', 'operator_route'),
                self::e('operator_route', 'wind_wait', ['condition' => ['field' => 'operator_certified', 'operator' => 'true'], 'label' => 'Certified']),
                self::e('operator_route', 'operator_fix', ['is_default' => true, 'label' => 'Not certified']), self::e('operator_fix', 'operator_check'),
                self::e('wind_wait', 'spray', ['outcome' => 'met']), self::e('wind_wait', 'wind_notice', ['outcome' => 'timeout']), self::e('wind_notice', 'wind_wait'),
                self::e('spray', 'spray_record'), self::e('spray_record', 'spray_done'),
                self::e('maturity', 'moisture_read', ['outcome' => 'met']), self::e('moisture_read', 'moisture_known'),
                self::e('moisture_known', 'moisture_route', ['condition' => ['field' => 'moisture_pct', 'operator' => 'not_empty'], 'label' => 'From the sensor']),
                self::e('moisture_known', 'moisture_task', ['is_default' => true, 'label' => 'Take it by hand']), self::e('moisture_task', 'moisture_route'),
                self::e('moisture_route', 'wet_choice', ['condition' => ['all' => [['field' => 'crop_code', 'operator' => 'eq', 'value' => 'MAIZE-W'], ['field' => 'moisture_pct', 'operator' => 'gt', 'value' => 14]]], 'label' => 'Too wet']),
                self::e('moisture_route', 'withhold_wait', ['is_default' => true, 'label' => 'Dry enough']),
                self::e('wet_choice', 'wet_route'),
                self::e('wet_route', 'recheck', ['condition' => ['field' => 'wet_choice', 'operator' => 'eq', 'value' => 'Wait and recheck in 3 days'], 'label' => 'Wait']),
                self::e('wet_route', 'drying', ['is_default' => true, 'label' => 'Dryer']), self::e('recheck', 'moisture_read'), self::e('drying', 'withhold_wait'),
                self::e('withhold_wait', 'harvest', ['outcome' => 'met']), self::e('harvest', 'harvest_record'), self::e('harvest_record', 'dest_route'),
                self::e('dest_route', 'to_processing', ['condition' => ['field' => 'destination', 'operator' => 'eq', 'value' => 'Processing'], 'label' => 'Processing']),
                self::e('dest_route', 'done', ['is_default' => true, 'label' => 'Silo']), self::e('to_processing', 'done'),
                self::e('damage_assess', 'damage_record'), self::e('damage_record', 'claim'), self::e('claim', 'replant_window', ['outcome' => 'completed']),
                self::e('claim', 'replant_window', ['outcome' => 'rejected']),
                self::e('replant_window', 'replant_choice', ['condition' => ['not' => ['field' => 'replant_window_end', 'operator' => 'before', 'value' => 'today']], 'label' => 'Window open']),
                self::e('replant_window', 'resume', ['is_default' => true, 'label' => 'Window closed']),
                self::e('replant_choice', 'replant_route'),
                self::e('replant_route', 'rain_wait', ['condition' => ['field' => 'replant_choice', 'operator' => 'eq', 'value' => 'Replant'], 'label' => 'Replant']),
                self::e('replant_route', 'resume', ['is_default' => true, 'label' => 'Carry on']),
                self::e('resume', 'scouting', ['condition' => ['field' => 'planted_on', 'operator' => 'not_empty'], 'label' => 'Planted']),
                self::e('resume', 'rain_wait', ['is_default' => true, 'label' => 'Not yet planted']),
            ], 'events' => [
                ['event' => 'agri.crop_damage', 'correlate' => ['event_key' => 'field_id', 'data_field' => 'field_id'], 'to' => 'damage_assess', 'interrupting' => true, 'store_as' => 'damage'],
            ]]];
    }

    public static function inputPurchase(): array
    {
        return ['key' => 'agri_input_purchase', 'name' => 'Farm input purchase', 'category' => 'Farm', 'module' => 'generic',
            'description' => 'One input for a field season (seed, fertiliser, chemicals or fuel): order, approval by value, order placed, delivery confirmed.',
            'trigger_type' => 'manual', 'trigger_config' => [],
            'forms' => ['order' => ['name' => 'Input order', 'fields' => [self::f('supplier', 'Supplier', 'text', true), self::f('amount', 'Order value', 'currency', true),
                self::f('expected_delivery', 'Expected delivery', 'date', true)]],
                'delivered' => ['name' => 'Delivery', 'fields' => [self::f('delivered_on', 'Delivered on', 'date', true), self::f('delivery_note', 'Delivery note number', 'text')]]],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Input needed', 40, 200),
                self::n('prepare', 'user_task', 'Prepare the input order', 220, 200, ['assignee' => self::requester(), 'form' => 'order']),
                self::n('value', 'decision', 'Above R50 000?', 400, 200),
                self::n('finance', 'approval', 'Finance approval', 580, 100, ['assignee' => self::owner(), 'mode' => 'any']),
                self::n('manager', 'approval', 'Farm manager approval', 580, 300, ['assignee' => self::owner(), 'mode' => 'any']),
                self::n('order', 'user_task', 'Place the order with the supplier', 760, 200, ['assignee' => self::requester()]),
                self::n('delivered', 'user_task', 'Confirm the delivery', 940, 200, ['assignee' => self::requester(), 'form' => 'delivered']),
                self::n('done', 'end', 'Delivered', 1120, 200, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Not approved', 760, 400, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('start', 'prepare'), self::e('prepare', 'value'),
                self::e('value', 'finance', ['condition' => ['field' => 'amount', 'operator' => 'gt', 'value' => 50000], 'label' => '> R50 000']),
                self::e('value', 'manager', ['is_default' => true, 'label' => 'Up to R50 000']),
                self::e('finance', 'order', ['outcome' => 'approved']), self::e('finance', 'rejected', ['outcome' => 'rejected']),
                self::e('manager', 'order', ['outcome' => 'approved']), self::e('manager', 'rejected', ['outcome' => 'rejected']),
                self::e('order', 'delivered'), self::e('delivered', 'done'),
            ]]];
    }

    public static function insuranceClaim(): array
    {
        return ['key' => 'agri_insurance_claim', 'name' => 'Crop insurance claim', 'category' => 'Farm', 'module' => 'generic',
            'description' => 'Lodge a crop damage claim, record the assessor\'s visit and decide on the settlement.',
            'trigger_type' => 'manual', 'trigger_config' => [],
            'forms' => ['lodge' => ['name' => 'Claim lodged', 'fields' => [self::f('insurer', 'Insurer', 'text', true), self::f('claim_ref', 'Claim number', 'text', true),
                self::f('estimated_loss', 'Estimated loss', 'currency', true)]],
                'assessed' => ['name' => 'Assessor visit', 'fields' => [self::f('assessed_on', 'Assessed on', 'date', true), self::f('settlement', 'Settlement offered', 'currency', true)]]],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Damage reported', 40, 200),
                self::n('lodge', 'user_task', 'Lodge the claim with the insurer', 220, 200, ['assignee' => self::requester(), 'form' => 'lodge']),
                self::n('assess', 'user_task', 'Record the assessor\'s visit', 400, 200, ['assignee' => self::requester(), 'form' => 'assessed']),
                self::n('settle', 'approval', 'Accept the settlement', 580, 200, ['assignee' => self::owner(), 'mode' => 'any']),
                self::n('done', 'end', 'Settled', 760, 120, ['outcome' => 'completed']),
                self::n('dispute', 'end', 'Disputed', 760, 300, ['outcome' => 'rejected']),
            ], 'edges' => [self::e('start', 'lodge'), self::e('lodge', 'assess'), self::e('assess', 'settle'),
                self::e('settle', 'done', ['outcome' => 'approved']), self::e('settle', 'dispute', ['outcome' => 'rejected'])]]];
    }
}
