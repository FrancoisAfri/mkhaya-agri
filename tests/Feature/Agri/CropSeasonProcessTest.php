<?php

namespace Tests\Feature\Agri;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\SoilTest;
use App\Modules\Agri\Models\WeatherReading;
use App\Modules\Agri\Processes\CropSeasonProcess;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTimer;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\TickService;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Process 1 — the crop production cycle, end to end on the workflow engine: one field season per
 * field, the rotation rule against the field's history, the lab result and the weather feed and
 * field sensors as triggers, hail interrupting the season, the withholding period blocking harvest
 * until its date, and scouting every 14 days.
 */
class CropSeasonProcessTest extends AgriTestCase
{
    private Farm $farm;

    private CropSeason $season;

    protected function setUp(): void
    {
        parent::setUp();
        // Start each test in the morning: the tests move the clock forward by hours, and a run started late in
        // the evening would cross midnight and close planting windows that the test means to keep open.
        $this->travelTo(today()->setTime(7, 0));
        $this->catalogue();
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => 'workflow'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_workflow');
        foreach (WorkflowPermissions::all() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->manager->givePermissionTo(WorkflowPermissions::all());
        $this->admin->givePermissionTo(WorkflowPermissions::all());
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->farm = $this->farm('BOPLAAS');
        $this->member($this->manager, $this->farm);
        $this->season = CropSeason::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'like', 'S'.(today()->month >= 8 ? today()->year : today()->year - 1).'/%')->firstOrFail();
        $this->as(fn () => null, $this->admin);
        $this->asCompany($this->company, function () {
            app(WorkflowSettings::class)->update(['publication_requires_review' => false], 'Test fixture');
            app(WorkflowSettings::class)->forget();
            CropSeasonProcess::install($this->admin); // the process owner approves; the farm manager runs the season
        });
    }

    private function as(callable $fn, ?User $user = null): mixed
    {
        $this->actingAs($user ?? $this->manager);

        return $this->asCompany($this->company, $fn);
    }

    private function tick(): void
    {
        $this->as(fn () => app(TickService::class)->run($this->company->id));
    }

    private function start(array $options = [], ?array $fields = null): WorkflowInstance
    {
        return $this->as(fn () => CropSeasonProcess::startSeason($this->farm, $this->season, $this->manager,
            $options + ['planting_window_start' => today()->toDateString(), 'planting_window_end' => today()->addDays(30)->toDateString(), 'field_ids' => $fields]));
    }

    private function fieldRun(WorkflowInstance $season, FarmField $field): WorkflowInstance
    {
        return WorkflowInstance::query()->withoutGlobalScopes()->where('parent_instance_id', $season->id)->get()
            ->firstWhere(fn ($i) => (int) ($i->data['field_id'] ?? 0) === $field->id) ?? $this->fail("No field season for {$field->code}.");
    }

    private function task(WorkflowInstance $i, string $node): ?WorkflowTask
    {
        return WorkflowTask::query()->withoutGlobalScopes()->where('instance_id', $i->id)->where('node_key', $node)->where('status', 'open')->first();
    }

    private function open(WorkflowInstance $i): array
    {
        return WorkflowTask::query()->withoutGlobalScopes()->where('instance_id', $i->id)->where('status', 'open')->orderBy('id')->pluck('node_key')->all();
    }

    private function do(WorkflowInstance $i, string $node, array $values = [], string $decision = 'completed', ?User $by = null): void
    {
        $t = $this->task($i, $node) ?? $this->fail(json_encode(DB::table('workflow_node_runs')->where('instance_id', $i->id)->orderBy('id')->get(['node_key', 'status', 'outcome', 'detail'])).json_encode(DB::table('workflow_action_runs')->where('instance_id', $i->id)->get())."No open \"{$node}\" task; open: ".implode(', ', $this->open($i)).'. Error: '.($i->fresh()->error ?? '-'));
        $by ??= User::query()->findOrFail($t->assignee_id ?? $this->manager->id);
        try {
            $this->as(fn () => app(TaskService::class)->decide($t->fresh(), $by, $decision, null, $values), $by);
        } catch (\Throwable $e) {
            $this->fail("{$node} ({$t->type}, assignee {$t->assignee_id}): ".$e->getMessage().json_encode(DB::table("workflow_node_runs")->where("instance_id", $i->id)->where("node_key", $node)->pluck("detail")));
        }
    }

    private function labResult(WorkflowInstance $i, float $ph): void
    {
        $this->as(fn () => SoilTest::withoutGlobalScopes()->findOrFail($i->fresh()->data['soil_test_id'])->update(['ph' => $ph, 'p_mg_kg' => 22]));
    }

    private function buyInputs(WorkflowInstance $field): void
    {
        $buys = WorkflowInstance::query()->withoutGlobalScopes()->where('parent_instance_id', $field->id)->whereHas('definition', fn ($q) => $q->where('key', 'agri_input_purchase'))->get();
        $this->assertCount(4, $buys, 'seed, fertiliser, chemicals and fuel, bought side by side');
        foreach ($buys as $n => $b) {
            $this->do($b, 'prepare', ['supplier' => 'Agri Co-op', 'amount' => $n === 0 ? 80000 : 12000, 'expected_delivery' => today()->toDateString()]);
            $this->do($b, $n === 0 ? 'finance' : 'manager', [], 'approved');
            $this->do($b, 'order');
            $this->do($b, 'delivered', ['delivered_on' => today()->toDateString()]);
            $this->assertSame('completed', $b->fresh()->status);
        }
    }

    private function rain(float $mm): void
    {
        $this->as(fn () => WeatherReading::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farm->id, 'provider' => 'test',
            'kind' => 'observed', 'period_start' => now()->subDay(), 'period_end' => now(), 'fetched_at' => now(), 'rain_mm' => $mm]));
    }

    private function sensor(FarmField $field, string $metric, float $value): void
    {
        $this->as(function () use ($field, $metric, $value) {
            $unit = DB::table('prd_units')->where('field_id', $field->id)->value('id') ?? DB::table('prd_units')->insertGetId(['uuid' => (string) Str::uuid(),
                'company_id' => $this->company->id, 'farm_id' => $this->farm->id, 'field_id' => $field->id, 'code' => 'U-'.$field->code, 'name' => "Field {$field->code}",
                'kind' => 'field', 'created_at' => now(), 'updated_at' => now()]);
            $device = DB::table('prd_devices')->where('unit_id', $unit)->where('metric', $metric)->value('id') ?? DB::table('prd_devices')->insertGetId(['uuid' => (string) Str::uuid(),
                'company_id' => $this->company->id, 'farm_id' => $this->farm->id, 'unit_id' => $unit, 'code' => strtoupper($metric).'-'.$field->code, 'name' => $metric,
                'metric' => $metric, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('prd_readings')->insert(['company_id' => $this->company->id, 'device_id' => $device, 'unit_id' => $unit, 'metric' => $metric, 'value' => $value,
                'measured_at' => now(), 'received_at' => now(), 'message_id' => (string) Str::uuid()]);
        });
    }

    /** Past crops on a field, one per earlier season, most recent last. */
    private function history(FarmField $field, array $crops): void
    {
        $this->as(function () use ($field, $crops) {
            foreach (array_values($crops) as $n => $code) {
                $year = (int) $this->season->starts_on->year - count($crops) + $n;
                $s = CropSeason::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->company->id, 'code' => "S{$year}H"],
                    ['name' => "Summer {$year} (history)", 'starts_on' => "{$year}-08-01", 'ends_on' => ($year + 1).'-07-31', 'status' => CropSeason::CLOSED]);
                CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farm->id, 'field_id' => $field->id, 'season_id' => $s->id,
                    'crop_type_id' => $this->cropTypeId($code), 'code' => "{$code}-H{$year}-{$field->code}", 'planned_area' => 10, 'area_unit' => 'ha',
                    'planned_planting_on' => "{$year}-11-01", 'planned_harvest_on' => ($year + 1).'-05-01', 'status' => CropPlan::COMPLETED, 'created_by' => $this->manager->id]);
            }
        });
    }

    /** Soil test, crop chosen, plan approved, inputs bought, rain — the field is planted ($daysAgo back). */
    private function toPlanted(WorkflowInstance $f, string $crop, int $daysAgo, float $ph = 6.4): WorkflowInstance
    {
        $this->labResult($f, $ph);
        $this->do($f, 'choose', ['crop' => $crop]);
        $this->do($f, 'plan_ok', [], 'approved');
        $this->buyInputs($f);
        $this->rain(32);
        $this->travel(61)->minutes();
        $this->tick();
        $this->do($f, 'plant', ['planted_on' => today()->subDays($daysAgo)->toDateString()]);

        return $f->fresh();
    }

    public function test_a_season_runs_one_field_season_per_field_with_the_rotation_rule_and_soil_lab_to_the_weighbridge(): void
    {
        $f1 = $this->field($this->farm, 'F1', 40);
        $f2 = $this->field($this->farm, 'F2', 25);
        $this->history($f1, ['MAIZE-Y', 'MAIZE-W']); // maize two seasons running (yellow then white — one crop)
        $this->history($f2, ['SOY', 'MAIZE-W']);

        $season = $this->start();
        $one = $this->fieldRun($season, $f1);
        $two = $this->fieldRun($season, $f2);
        $this->assertSame(2, WorkflowInstance::query()->withoutGlobalScopes()->where('parent_instance_id', $season->id)->count(), 'one field season per field');
        $this->assertSame(2, SoilTest::withoutGlobalScopes()->where('farm_id', $this->farm->id)->whereNull('ph')->count(), 'a sample goes to the lab for each field');
        $this->assertSame([], $this->open($one), 'waiting for the lab');

        // The lab result arrives for field 1 only (pH 5.1 → lime first); field 2 keeps waiting.
        $this->labResult($one, 5.1);
        $this->assertSame(['lime'], $this->open($one));
        $this->assertSame([], $this->open($two->fresh()));
        $this->do($one, 'lime', ['lime_t_per_ha' => 2, 'limed_on' => today()->toDateString()]);

        // Rotation: maize a third season on F1 is refused and the manager chooses again.
        $this->assertSame(['MAIZE-W', 'MAIZE-W'], $one->fresh()->data['rotation_history']);
        $this->assertSame('Maize', $one->fresh()->data['rotation_blocked']);
        $this->do($one, 'choose', ['crop' => 'Maize']);
        $this->assertFalse($one->fresh()->data['rotation_ok']);
        $this->assertSame(['choose'], $this->open($one), 'back to the crop choice');
        $this->do($one, 'choose', ['crop' => 'Soya']);
        $this->assertTrue($one->fresh()->data['rotation_ok']);
        $plan = CropPlan::withoutGlobalScopes()->findOrFail($one->fresh()->data['crop_plan_id']);
        $this->assertSame('SOY', $plan->cropType->code);
        $this->do($one, 'plan_ok', [], 'approved');
        $this->assertSame(CropPlan::APPROVED, $plan->fresh()->status);

        // Maize after soya is allowed on F2.
        $this->labResult($two, 6.5);
        $this->do($two, 'choose', ['crop' => 'Maize']);
        $this->assertTrue($two->fresh()->data['rotation_ok']);

        // Inputs: four purchases in parallel; the big order needs finance approval.
        $this->buyInputs($one);
        $this->assertSame([], $this->open($one), 'no rain yet');
        $this->travel(61)->minutes();
        $this->tick();
        $this->assertSame('rain_wait', DB::table('workflow_node_runs')->where('instance_id', $one->id)->where('status', 'waiting')->value('node_key'));

        // 25 mm in 7 days from the weather feed opens planting.
        $this->rain(12);
        $this->travel(361)->minutes();
        $this->tick();
        $this->assertSame([], $this->open($one), '12 mm is not enough');
        $this->rain(16);
        $this->travel(361)->minutes();
        $this->tick();
        $this->assertSame(['plant'], $this->open($one));
        $this->do($one, 'plant', ['planted_on' => today()->subDays(118)->toDateString()]);
        $this->assertSame(CropPlan::PLANTED, $plan->fresh()->status);

        // Maturity: soya (125 days) planted 118 days ago is ready in a week.
        $this->travel(8)->days();
        $this->tick();
        $this->assertSame(['moisture_task'], $this->open($one), 'no moisture sensor on F1: a reading by hand');
        $this->do($one, 'moisture_task', ['moisture_pct' => 12.5]);
        $this->do($one, 'harvest', ['quantity_t' => 96, 'destination' => 'Silo']);

        $h = HarvestRecord::withoutGlobalScopes()->where('crop_plan_id', $plan->id)->firstOrFail();
        $this->assertSame('weighbridge', $h->measuring_method);
        $this->assertEquals(2.4, $one->fresh()->data['yield_t_per_ha'], 'yield per field: 96 t on 40 ha');
        $this->assertSame('completed', $one->fresh()->status);
        $this->assertSame('running', $season->fresh()->status, 'the season waits for F2');
    }

    public function test_grain_moisture_from_the_field_sensor_gates_maize_with_a_three_day_recheck_or_the_dryer(): void
    {
        $f = $this->field($this->farm, 'F3', 30);
        $run = $this->fieldRun($this->start(), $f);
        $run = $this->toPlanted($run, 'Maize', 135); // DKC 78-79, 140 days: ready in five days
        $this->sensor($f, 'grain_moisture', 17.2);
        $this->travel(6)->days();
        $this->sensor($f, 'grain_moisture', 17.2);
        $this->tick();
        $this->assertSame(17.2, $run->fresh()->data['moisture_pct']);
        $this->assertSame('sensor', $run->fresh()->data['moisture_source']);
        $this->assertSame(['wet_choice'], $this->open($run), 'maize above 14%');

        $this->do($run, 'wet_choice', ['wet_choice' => 'Wait and recheck in 3 days']);
        $this->travel(2)->days();
        $this->tick();
        $this->assertSame([], $this->open($run), 'still inside the three days');
        $this->travel(25)->hours();
        $this->sensor($f, 'grain_moisture', 15.1);
        $this->tick();
        $this->assertSame(['wet_choice'], $this->open($run), 'rechecked: still wet');
        $this->do($run, 'wet_choice', ['wet_choice' => 'Send to the dryer', 'drying_cost' => 18500]);
        $this->assertTrue($run->fresh()->data['dried']);
        $this->assertSame(18500.0, (float) DB::table('field_activities')->where('crop_plan_id', $run->fresh()->data['crop_plan_id'])->where('title', 'like', 'Grain drying%')->value('labour_cost'));
        $this->assertSame(['harvest'], $this->open($run));
    }

    public function test_scouting_recurs_every_14_days_and_a_spray_needs_approval_a_certified_operator_calm_wind_and_blocks_harvest_until_the_withholding_date(): void
    {
        $this->travelTo(today()->setTime(7, 0)); // morning, so the hours travelled below never cross midnight
        $this->openBooks();
        $chem = $this->stockItem('CHEM-CYP', 'l', 300);
        $this->stockOnFarm($this->farm, $chem, 40, 300);
        $operator = $this->makeUser('operator@boplaas.test', null, $this->company);
        $worker = $this->worker($this->farm, 'Sipho', $operator);
        $f = $this->field($this->farm, 'F4', 20);
        $run = $this->toPlanted($this->fieldRun($this->start(), $f), 'Soya', 105); // ready in 20 days

        $this->assertSame(1, WorkflowTimer::query()->withoutGlobalScopes()->where('instance_id', $run->id)->where('kind', 'recurring')->where('status', 'pending')->count());
        $this->travel(14 * 24 + 1)->hours();
        $this->tick();
        $this->assertSame(['scout'], $this->open($run), 'first scouting round after 14 days');
        $this->do($run, 'scout', ['pest_found' => true, 'pest' => 'Bollworm', 'operator' => $operator->id]);
        $this->do($run, 'spray_approval', [], 'approved');
        $this->assertFalse($run->fresh()->data['operator_certified']);
        $this->assertSame(['operator_fix'], $this->open($run), 'no pesticide certificate: assign a certified operator');

        $this->competency($worker, config('agri.processes.spray_competency', 'PESTICIDE-APPLICATOR'));
        $this->sensor($f, 'wind_speed', 28);
        $this->do($run, 'operator_fix', ['operator' => $operator->id]);
        $this->assertTrue($run->fresh()->data['operator_certified']);
        $this->assertSame([], $this->open($run), 'too windy (28 km/h)');
        $this->travel(61)->minutes();
        $this->sensor($f, 'wind_speed', 9);
        $this->tick();
        $this->assertSame(['spray'], $this->open($run));
        $this->do($run, 'spray', ['chemical_code' => 'CHEM-CYP', 'chemical_quantity' => 6, 'withholding_days' => 21]);
        $until = today()->addDays(21)->toDateString();
        $this->assertSame($until, $run->fresh()->data['withholding_until']);

        // Maturity comes (day 20) but harvest is blocked until the withholding date (day 35).
        $this->travel(7)->days();
        $this->tick();
        $this->do($run, 'moisture_task', ['moisture_pct' => 12]);
        $this->assertNull($this->task($run, 'harvest'), 'inside the withholding period');
        $this->assertSame('withhold_wait', DB::table('workflow_node_runs')->where('instance_id', $run->id)->where('status', 'waiting')->value('node_key'));
        $this->travel(15)->days();
        $this->tick();
        $this->assertNotNull($this->task($run, 'harvest'), 'the withholding period is over');
        $this->assertGreaterThanOrEqual(2, WorkflowTask::query()->withoutGlobalScopes()->where('instance_id', $run->id)->where('node_key', 'scout')->count(), 'scouting went on every 14 days');

        $this->do($run, 'harvest', ['quantity_t' => 50, 'destination' => 'Processing']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertStringContainsString('not published', (string) $run->fresh()->data['processing_note'], 'process 3 is not installed yet');
        $this->assertSame(0, WorkflowTimer::query()->withoutGlobalScopes()->where('instance_id', $run->id)->where('status', 'pending')->count(), 'scouting stops at harvest');
    }

    public function test_hail_interrupts_the_field_season_opens_a_claim_and_replants_inside_the_window(): void
    {
        $f = $this->field($this->farm, 'F5', 15);
        $spared = $this->field($this->farm, 'F6', 15);
        $season = $this->start(['replant_window_end' => today()->addDays(40)->toDateString()]);
        $run = $this->toPlanted($this->fieldRun($season, $f), 'Soya', 10);
        $other = $this->toPlanted($this->fieldRun($season, $spared), 'Soya', 10);

        $r = $this->as(fn () => CropSeasonProcess::reportDamage($f, 'hail', 'Hail at 15:00, stones 20 mm', $this->manager));
        $this->assertSame(1, $r['interrupted']);
        $this->assertSame(['damage_assess'], $this->open($run));
        $this->assertSame('hail', $run->fresh()->data['damage']['kind']);
        $this->assertSame(0, WorkflowTimer::query()->withoutGlobalScopes()->where('instance_id', $run->id)->where('status', 'pending')->count(), 'the season\'s waits and scouting stopped');
        $this->assertSame(1, WorkflowTimer::query()->withoutGlobalScopes()->where('instance_id', $other->id)->where('kind', 'recurring')->where('status', 'pending')->count(), 'F6 is not touched');

        $this->do($run, 'damage_assess', ['damage_pct' => 70, 'damage_notes' => 'Most plants stripped']);
        $obs = CropObservation::withoutGlobalScopes()->findOrFail($run->fresh()->data['damage_observation_id']);
        $this->assertSame('weather_damage', $obs->kind);
        $this->assertSame('high', $obs->severity);
        $claim = WorkflowInstance::query()->withoutGlobalScopes()->where('parent_instance_id', $run->id)->whereHas('definition', fn ($q) => $q->where('key', 'agri_insurance_claim'))->firstOrFail();
        $this->assertNotNull($this->task($claim, 'lodge'), 'the insurance claim runs on its own');

        $this->assertSame(['replant_choice'], $this->open($run), 'inside the replant window');
        $this->do($run, 'replant_choice', ['replant_choice' => 'Replant']);
        $this->assertSame(['plant'], $this->open($run), 'rain is in: plant again');
        $this->do($run, 'plant', ['planted_on' => today()->toDateString()]);
        $this->assertSame(today()->toDateString(), CropPlan::withoutGlobalScopes()->find($run->fresh()->data['crop_plan_id'])->planted_on->toDateString());
        $this->assertSame(1, WorkflowTimer::query()->withoutGlobalScopes()->where('instance_id', $run->id)->where('kind', 'recurring')->where('status', 'pending')->count(), 'scouting starts again');
    }

    public function test_hail_after_the_replant_window_carries_on_with_the_crop(): void
    {
        $f = $this->field($this->farm, 'F7', 15);
        $run = $this->toPlanted($this->fieldRun($this->start(['replant_window_end' => today()->subDay()->toDateString()]), $f), 'Soya', 30);
        $this->as(fn () => CropSeasonProcess::reportDamage($f, 'drought', null, $this->manager));
        $this->do($run, 'damage_assess', ['damage_pct' => 20, 'damage_notes' => 'Leaf curl on the ridges']);
        $this->assertNull($this->task($run, 'replant_choice'), 'the window closed: no replant offered');
        $this->assertSame(1, WorkflowTimer::query()->withoutGlobalScopes()->where('instance_id', $run->id)->where('kind', 'recurring')->where('status', 'pending')->count(), 'back to scouting');
    }

    public function test_inputs_late_for_the_planting_window_offer_a_delay_or_a_shorter_season_variety(): void
    {
        $f = $this->field($this->farm, 'F8', 20);
        $run = $this->fieldRun($this->start(['planting_window_start' => today()->addDays(2)->toDateString()]), $f);
        $this->labResult($run, 6.2);
        $this->do($run, 'choose', ['crop' => 'Soya']);
        $this->do($run, 'plan_ok', [], 'approved');
        $this->assertSame('DM 5953RSF', $run->fresh()->data['variety_name'], 'the full-season variety by default');

        $this->travel(3)->days();
        $this->tick();
        $this->assertSame(['late_choice'], $this->open($run), 'nothing delivered by the window: the manager decides');
        $this->do($run, 'late_choice', ['late_choice' => 'Switch to a shorter-season variety']);
        $this->assertSame('PAN 1521R', $run->fresh()->data['variety_name']);
        $this->assertSame('PAN 1521R', CropPlan::withoutGlobalScopes()->find($run->fresh()->data['crop_plan_id'])->variety->name);
        $this->assertSame('rain_wait', DB::table('workflow_node_runs')->where('instance_id', $run->id)->where('status', 'waiting')->value('node_key'));
    }

    public function test_the_planting_window_closing_without_rain_asks_to_plant_dry_change_crop_or_leave_fallow(): void
    {
        $f = $this->field($this->farm, 'F9', 20);
        $run = $this->fieldRun($this->start(['planting_window_end' => today()->addDays(5)->toDateString()]), $f);
        $this->labResult($run, 6.2);
        $this->do($run, 'choose', ['crop' => 'Wheat']);
        $this->do($run, 'plan_ok', [], 'approved');
        $this->buyInputs($run);
        $this->travel(61)->minutes();
        $this->tick();
        $this->rain(8);
        $this->travel(6)->days();
        $this->tick();
        $this->assertSame(['window_choice'], $this->open($run));
        $this->do($run, 'window_choice', ['window_choice' => 'Leave fallow']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(CropPlan::CANCELLED, CropPlan::withoutGlobalScopes()->find($run->fresh()->data['crop_plan_id'])->status);
    }

    public function test_a_season_starts_itself_on_its_planning_date_once_per_farm(): void
    {
        $this->field($this->farm, 'G1', 10);
        $next = $this->as(fn () => CropSeason::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'NEXT', 'name' => 'Next season',
            'starts_on' => today()->addDays(45), 'ends_on' => today()->addDays(400), 'status' => CropSeason::OPEN]));
        $count = fn () => WorkflowInstance::query()->withoutGlobalScopes()->where('dedupe_key', "agri-season:{$this->farm->id}:{$next->id}")->count();

        $this->as(fn () => CropSeasonProcess::startDue());
        $this->assertSame(0, $count(), 'not yet: 45 days out, the lead is 30');
        $this->travel(16)->days();
        $this->artisan('agri:start-seasons', ['--company' => $this->company->id])->assertSuccessful();
        $this->assertSame(1, $count());
        $this->artisan('agri:start-seasons', ['--company' => $this->company->id])->assertSuccessful();
        $this->assertSame(1, $count(), 'once per farm and season');
    }

    public function test_the_season_page_starts_a_season_takes_the_lab_result_and_reports_hail(): void
    {
        $cat = DB::table('security_categories')->where('name', 'Operations & Support')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Operations & Support', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(['code_name' => 'manufacturing'], ['uuid' => (string) Str::uuid(),
            'name' => 'Manufacturing & Production', 'category_id' => $cat, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => 'manufacturing'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$this->manager, $this->worker] as $u) {
            DB::table('security_module_access')->updateOrInsert(['module_id' => DB::table('security_modules')->where('code_name', 'manufacturing')->value('id'), 'user_id' => $u->id],
                ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget('module_active_manufacturing');
        Cache::forget('hub_module_id:manufacturing');
        $Mfg = \App\Modules\Operations\Manufacturing\Support\RoleCatalog::class;
        foreach ([$Mfg::VIEW_AGRICULTURE, $Mfg::RECORD_AGRICULTURE, $Mfg::MANAGE_AGRICULTURE] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->manager->givePermissionTo([$Mfg::VIEW_AGRICULTURE, $Mfg::RECORD_AGRICULTURE, $Mfg::MANAGE_AGRICULTURE]);
        $this->worker->givePermissionTo([$Mfg::VIEW_AGRICULTURE]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $f = $this->field($this->farm, 'P1', 12);
        $this->actingAs($this->manager)->get(route('manufacturing.agriculture.seasons.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Manufacturing/Agriculture/Seasons')->where('installed', true)->has('fields', 0));
        $this->actingAs($this->manager)->post(route('manufacturing.agriculture.seasons.start'), ['farm_id' => $this->farm->uuid, 'season_id' => $this->season->uuid,
            'planting_window_start' => today()->toDateString(), 'planting_window_end' => today()->addDays(30)->toDateString()])->assertSessionHasNoErrors();

        $row = $this->actingAs($this->manager)->get(route('manufacturing.agriculture.seasons.index'))->viewData('page')['props']['fields'][0];
        $this->assertSame(['Wait for the lab result'], $row['waiting']);
        $this->actingAs($this->manager)->post(route('manufacturing.agriculture.seasons.soil', $row['soil_test']), ['ph' => 6.3])->assertSessionHasNoErrors();
        $row = $this->actingAs($this->manager)->get(route('manufacturing.agriculture.seasons.index'))->viewData('page')['props']['fields'][0];
        $this->assertEquals(6.3, $row['ph']);
        $this->assertSame(['Choose the crop'], $row['tasks']);

        $this->actingAs($this->manager)->post(route('manufacturing.agriculture.seasons.damage'), ['field_id' => $f->uuid, 'kind' => 'hail'])->assertSessionHas('success', fn ($m) => str_contains($m, 'damage assessment'));
        $this->assertSame(['Assess the damage'], $this->actingAs($this->manager)->get(route('manufacturing.agriculture.seasons.index'))->viewData('page')['props']['fields'][0]['tasks']);
        $this->actingAs($this->worker)->post(route('manufacturing.agriculture.seasons.start'), ['farm_id' => $this->farm->uuid, 'season_id' => $this->season->uuid,
            'planting_window_start' => today()->toDateString(), 'planting_window_end' => today()->addDays(30)->toDateString()])->assertForbidden();
    }
}
