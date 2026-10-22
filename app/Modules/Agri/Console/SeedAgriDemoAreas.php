<?php

namespace App\Modules\Agri\Console;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\CropInputTemplate;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FarmCapexPlan;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLoan;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\FeedRation;
use App\Modules\Agri\Models\GradingStandard;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\MarketRequest;
use App\Modules\Agri\Models\OverheadRule;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\ScoutingThreshold;
use App\Modules\Agri\Models\VaccinationProgramme;
use App\Modules\Agri\Models\WeatherWarning;
use App\Modules\Agri\Services\AgriDrafts;
use App\Modules\Agri\Services\AgriLedger;
use App\Modules\Agri\Services\AgriPredictions;
use App\Modules\Agri\Services\CropImagery;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Services\CropLifecycle;
use App\Modules\Agri\Services\DecisionSimulator;
use App\Modules\Agri\Services\EnvironmentReport;
use App\Modules\Agri\Services\FarmBudgets;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\FarmOutcomes;
use App\Modules\Agri\Services\FarmProcurement;
use App\Modules\Agri\Services\FarmSafety;
use App\Modules\Agri\Services\FarmSales;
use App\Modules\Agri\Services\FarmStores;
use App\Modules\Agri\Services\FinanceIntegration;
use App\Modules\Agri\Services\HarvestPlanning;
use App\Modules\Agri\Services\Harvests;
use App\Modules\Agri\Services\InputPlanning;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Agri\Services\Irrigation;
use App\Modules\Agri\Services\Livestock;
use App\Modules\Agri\Services\LivestockIntelligence;
use App\Modules\Agri\Services\MachineMaintenance;
use App\Modules\Agri\Services\Outgrowers;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Agri\Services\ProduceDispatches;
use App\Modules\Agri\Services\WeatherIntelligence;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift;
use App\Modules\HR\Hr\Attendance\Models\ShiftTemplate;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Services\Attendance;
use App\Modules\HR\Hr\Attendance\Services\Rostering;
use App\Modules\HR\Hr\Attendance\Services\Workforce;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Operations\Sheq\Services\ComplianceSchemes;
use App\Modules\Shared\Party\PartyDirectory;
use App\Modules\Tenancy\Services\TenantContext;
use App\Shared\Insights\ReportSchedule;
use App\Shared\Insights\ScheduledReports;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tops up the agri:demo organisation so every screen has something on it: maps, stores and the
 * packhouse chain (harvest → grading/packing run → sales order → pick, count, dispatch, deliver →
 * recall), budgets and finance plans, irrigation, soil, weather, markets, livestock feeding and
 * production, outgrowers, SHEQ compliance, Fleet faults and pre-start checks, integrations, office
 * staff on Time & Attendance, scheduled insights, outcomes and predictions.
 *
 * Each area checks for its own marker and is skipped when it is already there, and runs in its
 * own transaction: one area failing is reported and rolled back, and the rest carry on. Safe to
 * run again. Goes through the module services wherever one exists, so stock and ledger postings
 * are what real use would make; reference rows with no service are written as the screens do.
 */
class SeedAgriDemoAreas extends Command
{
    protected $signature = 'agri:demo-more {--company=1 : Organisation that holds the agri:demo farms}';

    protected $description = 'Fill every Mkhaya Agri area (packhouse, sales, irrigation, outgrowers, compliance, T&A office staff…) for the demo organisation; re-runnable';

    private int $companyId;

    /** @var array<string, User> */
    private array $u = [];

    private Farm $boplaas;

    private Farm $rivier;

    private array $results = [];

    public function handle(TenantContext $tenants): int
    {
        $this->companyId = (int) $this->option('company');

        return $tenants->run($this->companyId, function () {
            $b = Farm::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', 'BOPLAAS')->first();
            $r = Farm::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', 'RIVIER')->first();
            if (! $b || ! $r) {
                $this->error('Run php artisan agri:demo first: the demo farms are missing.');

                return self::FAILURE;
            }
            $this->boplaas = $b;
            $this->rivier = $r;
            foreach (['owner', 'manager', 'supervisor', 'worker', 'finance', 'herdsman', 'auditor'] as $k) {
                $user = User::query()->where('email', "{$k}@boplaas.demo")->first();
                if ($user) {
                    $this->u[$k] = $user;
                }
            }
            if (! isset($this->u['owner'], $this->u['manager'], $this->u['supervisor'], $this->u['finance'])) {
                $this->error('The demo users are missing; run php artisan agri:demo first.');

                return self::FAILURE;
            }
            Auth::setUser($this->u['owner']);

            // The owner follows the farm into every module it now runs on (Fleet, Warehouse,
            // Manufacturing, Accounting, SHEQ, Time & Attendance…), so give them the whole organisation.
            // The farm's people work their roster and timesheets in Time & Attendance, so it is in their menu.
            $this->area('Farm roles reach Time & Attendance', fn () => $this->farmTimeAccess(true), fn () => $this->farmTimeAccess(false));
            $this->area('Owner reaches every module', fn () => $this->ownerHasEverything(), fn () => $this->ownerEverything());
            $this->area('Farm map (boundaries, fields, locations)', fn () => $this->boplaas->fresh()->boundary !== null, fn () => $this->map());
            $this->area('Field maps (management zones, boundary history)', fn () => \App\Modules\Agri\Models\FieldZone::withoutGlobalScopes()->where('company_id', $this->companyId)->exists(), fn () => $this->fieldMaps());
            $this->area('Catalogue (chemicals, input rates, grading standard)', fn () => GradingStandard::query()->where('name', 'Lemons — CGA export standard')->exists(), fn () => $this->catalogue());
            $this->area('Budgets (revisions, new lines)', fn () => DB::table('farm_budget_revisions')->where('company_id', $this->companyId)->exists(), fn () => $this->budgets());
            $this->area('Finance plans (loans, capex, overheads, export)', fn () => FarmLoan::query()->where('reference', 'LB-PL-88213')->exists(), fn () => $this->financePlans());
            $this->area('Irrigation (zones, events, exceptions, moisture)', fn () => IrrigationZone::query()->where('code', 'L1-PIVOT')->exists(), fn () => $this->irrigation());
            $this->area('Soil tests', fn () => DB::table('soil_tests')->where('company_id', $this->companyId)->exists(), fn () => $this->soil());
            $this->area('Weather (rules, warnings)', fn () => DB::table('agri_weather_rules')->where('company_id', $this->companyId)->exists(), fn () => $this->weather());
            $this->area('Market prices and buyer requests', fn () => MarketPrice::query()->exists(), fn () => $this->market());
            $this->area('Crops (stages, thresholds, imagery, simulation, commitment)', fn () => DB::table('crop_stage_events')->where('company_id', $this->companyId)->exists(), fn () => $this->crops());
            $this->area('Satellite crop health (NDVI per field and zone, pictures)', fn () => \App\Modules\Agri\Models\SatelliteScene::withoutGlobalScopes()->where('company_id', $this->companyId)->exists(), fn () => $this->satellite());
            $this->area('Livestock (rations, feeding, vaccination, movements, production)', fn () => FeedRation::query()->exists(), fn () => $this->livestock());
            $this->area('Cold store and packhouse runs', fn () => FarmStore::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('kind', 'cold_room')->exists(), fn () => $this->packhouse());
            $this->area('Storage readings and sensors', fn () => DB::table('storage_readings')->where('company_id', $this->companyId)->exists(), fn () => $this->storage());
            $this->area('Sales order, dispatch and delivery', fn () => ProduceDispatches::ordersFor($this->rivier)->exists(), fn () => $this->sales());
            $this->area('Sales contract, listing', fn () => DB::table('agri_sales_contracts')->where('company_id', $this->companyId)->exists(), fn () => $this->contracts());
            $this->area('Recall', fn () => app(PostHarvest::class)->recallsFor($this->rivier)->exists(), fn () => $this->recall());
            $this->area('Outgrowers', fn () => Grower::withoutGlobalScopes()->where('company_id', $this->companyId)->exists(), fn () => $this->outgrowers());
            $this->area('SHEQ compliance (sites, schemes, register)', fn () => DB::table('sheq_compliance_schemes')->where('company_id', $this->companyId)->where('code', 'globalgap-ifa-v6')->exists(), fn () => $this->sheq());
            $this->area('Fleet (faults, pre-start, warranty, telematics)', fn () => DB::table('fleet_prestart_checks')->where('company_id', $this->companyId)->exists(), fn () => $this->fleet());
            $this->area('Office staff on Time & Attendance', fn () => ShiftTemplate::query()->where('code', 'OFFICE')->exists(), fn () => $this->officeStaff());
            $this->area('Environment records and supplier disputes', fn () => DB::table('agri_environment_records')->where('company_id', $this->companyId)->exists(), fn () => $this->environment());
            $this->area('Scheduled insights', fn () => ReportSchedule::query()->where('company_id', $this->companyId)->where('report_key', 'like', 'agri:%')->exists(), fn () => $this->insights());
            $this->area('Outcomes, predictions, AI drafts', fn () => DB::table('agri_outcome_snapshots')->where('company_id', $this->companyId)->exists(), fn () => $this->outcomes());
            // Production moved to Manufacturing & Production: cycles, sensors, the Command Centre, intelligence.
            $this->area('Agricultural production, Command Centre and intelligence', fn () => \App\Modules\Operations\Manufacturing\Models\Agriculture\Unit::query()->where('code', 'BH1')->exists(), fn () => $this->production());
            $this->area('Production gateway, processing line and forecasts', fn () => \App\Modules\Operations\Manufacturing\Models\Agriculture\Device::query()->where('code', 'RIV-GW1')->exists(), fn () => $this->productionMore());
            $this->area('Production roadmap 4–8 (checklists, CCP, verification, notices, dairy, scenarios)', fn () => \App\Modules\Operations\Manufacturing\Models\Agriculture\Threshold::query()->where('is_ccp', true)->exists(), fn () => $this->productionRoadmap());
            $this->area('Season processes (crop cycle on the workflow engine)', fn () => \App\Modules\Workflow\Models\WorkflowDefinition::query()->where('key', 'agri_field_season')->exists(), fn () => $this->seasonProcesses());

            $this->newLine();
            $failed = collect($this->results)->filter(fn ($r) => str_starts_with($r, 'FAILED'))->count();
            $this->line($failed ? "<error>{$failed} area(s) failed.</error>" : '<info>Every area is filled.</info>');

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }

    private function area(string $name, callable $present, callable $fill): void
    {
        try {
            if ($present()) {
                $this->results[$name] = 'already there';
                $this->line(sprintf('  %-62s <comment>already there</comment>', $name));

                return;
            }
            Auth::setUser($this->u['owner']);
            $note = DB::transaction(fn () => $fill());
            $this->results[$name] = 'filled';
            $this->line(sprintf('  %-62s <info>filled</info>%s', $name, is_string($note) ? " ({$note})" : ''));
        } catch (\Throwable $e) {
            $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();
            // Where in this seeder it went wrong, and where it was thrown.
            $line = collect($e->getTrace())->first(fn ($f) => ($f['file'] ?? '') === __FILE__)['line'] ?? null;
            $where = ($line ? " (seeder line {$line})" : '').($e instanceof ValidationException ? '' : ' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine());
            $this->results[$name] = 'FAILED: '.$msg;
            $this->line(sprintf('  %-62s <error>FAILED: %s</error>%s', $name, mb_strimwidth($msg, 0, 300), $where));
        } finally {
            Auth::setUser($this->u['owner']);
        }
    }

    /** Managers, supervisors and finance manage time (MODIFY); workers clock in (WRITE). */
    private function farmTimeAccess(bool $check): bool|string
    {
        $module = DB::table('security_modules')->where('code_name', 'time_attendance')->where('active', 1)->first(['id', 'category_id']);
        if (! $module) {
            return $check ? true : 'Time & Attendance is not installed';
        }
        $levels = ['manager' => 3, 'supervisor' => 3, 'finance' => 3, 'worker' => 2, 'herdsman' => 2];
        $done = 0;
        foreach ($levels as $who => $level) {
            $user = $this->u[$who] ?? null;
            if (! $user) {
                continue;
            }
            $has = DB::table('security_module_access')->where('module_id', $module->id)->where('user_id', $user->id)->where('access_level', '>=', $level)->exists();
            if ($check) {
                if (! $has) {
                    return false;
                }
                continue;
            }
            DB::table('security_module_access')->updateOrInsert(['module_id' => $module->id, 'user_id' => $user->id],
                ['access_level' => $level, 'active' => 1, 'updated_at' => now(), 'created_at' => now()]);
            if ($module->category_id && ! DB::table('security_category_access')->where('category_id', $module->category_id)->where('user_id', $user->id)->exists()) {
                DB::table('security_category_access')->insert(['category_id' => $module->category_id, 'user_id' => $user->id,
                    'access_level' => $level, 'active' => 1, 'updated_at' => now(), 'created_at' => now()]);
            }
            $done++;
        }

        return $check ? true : "{$done} farm roles";
    }

    private function ownerHasEverything(): bool
    {
        $owner = $this->u['owner'];
        $modules = DB::table('security_modules')->where('active', 1)->pluck('id');

        return $owner->hasRole('Organisation Owner')
            && DB::table('security_module_access')->where('user_id', $owner->id)->whereIn('module_id', $modules)->where('access_level', '>=', 5)->count() === $modules->count()
            && \Spatie\Permission\Models\Permission::query()->where('guard_name', 'web')->count() === $owner->getAllPermissions()->count();
    }

    private function ownerEverything(): string
    {
        $owner = $this->u['owner'];
        $role = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'Organisation Owner', 'guard_name' => 'web']);
        $role->syncPermissions(\Spatie\Permission\Models\Permission::query()->where('guard_name', 'web')->get());
        if (! $owner->hasRole($role)) {
            $owner->assignRole($role);
        }
        $modules = DB::table('security_modules')->where('active', 1)->get(['id', 'category_id']);
        foreach ($modules as $m) {
            DB::table('security_module_access')->updateOrInsert(['module_id' => $m->id, 'user_id' => $owner->id],
                ['access_level' => 5, 'active' => 1, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ($modules->pluck('category_id')->filter()->unique() as $cat) {
            DB::table('security_category_access')->updateOrInsert(['category_id' => $cat, 'user_id' => $owner->id],
                ['access_level' => 5, 'active' => 1, 'updated_at' => now(), 'created_at' => now()]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Illuminate\Support\Facades\Cache::flush();

        return $modules->count().' modules, '.$role->permissions()->count().' permissions';
    }

    /**
     * The production engine on the demo farms: broiler and layer houses and a tilapia pond at
     * Rivierplaas, the maize on Boplaas as a crop cycle, a day of sensor readings (with a house
     * running hot, a sensor gone quiet and a fan command waiting for approval), outside sources
     * (one failing), a heat warning, an outbreak report near Rivierplaas, a funding call, the
     * agents' recommendations and weight forecasts. Every unit is marked as demonstration data.
     */
    private function production(): string
    {
        // The demo is seeded without calling the AI service: the brief is written when the agents next run.
        config(['manufacturing.agriculture.ai_brief' => false]);
        $Mfg = \App\Modules\Operations\Manufacturing\Support\RoleCatalog::class;
        foreach ($Mfg::all() as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $owner = $this->u['owner'];
        $owner->givePermissionTo($Mfg::all());
        // The farm's people respond to the Command Centre; the manager also decides and approves.
        $mfgModule = DB::table('security_modules')->where('code_name', 'manufacturing')->value('id');
        foreach (['manager', 'supervisor', 'herdsman'] as $k) {
            if (! isset($this->u[$k])) {
                continue;
            }
            $this->u[$k]->givePermissionTo(array_merge([$Mfg::VIEW_AGRICULTURE, $Mfg::RECORD_AGRICULTURE, $Mfg::VIEW_COMMAND, $Mfg::RESPOND_INCIDENTS, $Mfg::VIEW_INTELLIGENCE],
                $k === 'manager' ? [$Mfg::MANAGE_AGRICULTURE, $Mfg::APPROVE_COMMANDS, $Mfg::DECIDE_RECOMMENDATIONS, $Mfg::REQUEST_COMMANDS, $Mfg::INSPECT, $Mfg::DISPOSE] : [$Mfg::REQUEST_COMMANDS]));
            if ($mfgModule) {
                DB::table('security_module_access')->updateOrInsert(['module_id' => $mfgModule, 'user_id' => $this->u[$k]->id],
                    ['access_level' => $k === 'manager' ? 3 : 2, 'active' => 1, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $owner = $owner->fresh();
        Auth::setUser($owner);

        $types = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionTypes::class);
        $types->installDefaults($this->companyId);
        $type = fn (string $code) => \App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType::query()->where('code', $code)->firstOrFail();
        $cycles = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionCycles::class);
        $readings = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Readings::class);
        $Unit = \App\Modules\Operations\Manufacturing\Models\Agriculture\Unit::class;
        $Device = \App\Modules\Operations\Manufacturing\Models\Agriculture\Device::class;
        $unit = fn (Farm $farm, string $code, string $name, string $kind, ?string $t, array $o = []) => $Unit::create($o + ['company_id' => $this->companyId, 'farm_id' => $farm->id,
            'code' => $code, 'name' => $name, 'kind' => $kind, 'production_type_id' => $t ? $type($t)->id : null, 'attributes' => ['demo' => true]]);

        $bh1 = $unit($this->rivier, 'BH1', 'Broiler house 1', 'house', 'BROILER', ['capacity' => 30000, 'capacity_unit' => 'birds', 'area' => 1500, 'area_unit' => 'm2', 'biosecurity_zone' => 'Poultry A']);
        $bh2 = $unit($this->rivier, 'BH2', 'Broiler house 2', 'house', 'BROILER', ['capacity' => 30000, 'capacity_unit' => 'birds', 'area' => 1500, 'area_unit' => 'm2', 'biosecurity_zone' => 'Poultry A', 'status' => 'cleaning']);
        $lh1 = $unit($this->rivier, 'LH1', 'Layer house 1', 'house', 'LAYER', ['capacity' => 8000, 'capacity_unit' => 'birds', 'area' => 1100, 'area_unit' => 'm2', 'biosecurity_zone' => 'Poultry B']);
        $pond = $unit($this->rivier, 'POND1', 'Tilapia pond 1', 'pond', 'TILAPIA', ['capacity' => 15000, 'capacity_unit' => 'fish', 'area' => 2000, 'area_unit' => 'm2']);
        $plan = CropPlan::withoutGlobalScopes()->where('farm_id', $this->boplaas->id)->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->where('code', 'like', 'MZ%')->first();
        $land = $plan ? $unit($this->boplaas, 'FLD-'.$plan->code, 'Maize — '.($plan->field?->name ?? $plan->code), 'field', 'MAIZE', ['field_id' => $plan->field_id, 'area' => $plan->planned_area, 'area_unit' => 'ha']) : null;

        // A broiler flock, day 24: daily feed and water, a little mortality, weekly weighings.
        $placed = today()->subDays(24);
        $flock = $cycles->start($type('BROILER'), $bh1, ['code' => 'BRO-2610-01', 'name' => 'Ross 308 flock October', 'opening_count' => 28000,
            'started_on' => $placed->toDateString(), 'breed' => 'Ross 308', 'source_ref' => 'Limpopo Hatchery batch 4471', 'custom' => ['hatchery' => 'Limpopo Hatchery']], $owner);
        foreach (['brooding' => 1, 'grower' => 11] as $stage => $day) {
            \App\Modules\Operations\Manufacturing\Models\Agriculture\StageEvent::create(['company_id' => $this->companyId, 'cycle_id' => $flock->id, 'from_stage' => $flock->fresh()->stage,
                'to_stage' => $stage, 'at' => $placed->copy()->addDays($day)->setTime(7, 0), 'by' => $owner->id]);
            $flock->update(['stage' => $stage]);
        }
        $weights = [7 => 0.19, 14 => 0.49, 21 => 0.97];
        for ($d = 1; $d <= 23; $d++) {
            $day = $placed->copy()->addDays($d);
            $alive = (int) $flock->fresh()->current_count;
            $intake = min(0.17, 0.012 + 0.0068 * $d);                       // kg a bird a day, rising
            $feed = round($alive * $intake);
            $cycles->record($flock->fresh(), 'feed', ['quantity' => $feed, 'cost' => round($feed * 8.4, 2), 'recorded_at' => $day->copy()->setTime(7, 30), 'client_id' => "demo-bro-feed-{$d}"], $owner);
            $cycles->record($flock->fresh(), 'water', ['quantity' => round($feed * 1.85), 'recorded_at' => $day->copy()->setTime(18, 0), 'client_id' => "demo-bro-water-{$d}"], $owner);
            $dead = $d <= 3 ? 55 - $d * 10 : ($d === 22 ? 96 : 8 + ($d % 5));
            $cycles->record($flock->fresh(), 'mortality', ['count' => $dead, 'reason' => $d <= 3 ? 'Starve-outs' : ($d === 22 ? 'Heat stress' : 'Found dead'), 'recorded_at' => $day->copy()->setTime(8, 0), 'client_id' => "demo-bro-mort-{$d}"], $owner);
            if (isset($weights[$d])) {
                $cycles->record($flock->fresh(), 'weight', ['quantity' => $weights[$d], 'sample_size' => 100, 'uniformity_pct' => 84 - $d / 7, 'recorded_at' => $day->copy()->setTime(9, 0), 'client_id' => "demo-bro-wt-{$d}"], $owner);
            }
        }
        $cycles->record($flock->fresh(), 'treatment', ['product_name' => 'Vitamin & electrolyte (Stress Pack)', 'dose' => '1 g/l', 'reason' => 'Heat stress', 'recorded_at' => $placed->copy()->addDays(22)->setTime(10, 0)], $owner);

        // Layers in lay, with eggs collected; the tilapia pond; the maize as a crop cycle.
        $layers = $cycles->start($type('LAYER'), $lh1, ['code' => 'LAY-2604-01', 'name' => 'Hy-Line Brown flock', 'opening_count' => 7600, 'started_on' => today()->subDays(196)->toDateString(), 'breed' => 'Hy-Line Brown'], $owner);
        $cycles->advance($layers, 'laying', $owner);
        for ($d = 14; $d >= 1; $d--) {
            $cycles->record($layers->fresh(), 'eggs', ['quantity' => 6650 + random_int(-120, 120), 'recorded_at' => today()->subDays($d)->setTime(15, 0), 'client_id' => "demo-lay-eggs-{$d}"], $owner);
            $cycles->record($layers->fresh(), 'feed', ['quantity' => 870, 'cost' => 870 * 7.9, 'recorded_at' => today()->subDays($d)->setTime(7, 0), 'client_id' => "demo-lay-feed-{$d}"], $owner);
        }
        $fish = $cycles->start($type('TILAPIA'), $pond, ['code' => 'TIL-2607-01', 'name' => 'Nile tilapia stocking', 'opening_count' => 12000, 'started_on' => today()->subDays(90)->toDateString(), 'area' => 0.2], $owner);
        $cycles->advance($fish, 'grow_out', $owner);
        if ($land) {
            $cycles->start($type('MAIZE'), $land, ['crop_plan_id' => $plan->id, 'code' => 'MZ-CYC-'.$plan->code, 'started_on' => ($plan->planted_on ?? today()->subMonths(4))->toDateString()], $owner);
        }

        // A day of readings in broiler house 1, the last forty minutes running hot.
        $dev = fn ($u, string $code, string $name, string $metric, ?string $label, array $o = []) => $Device::create($o + ['company_id' => $this->companyId, 'farm_id' => $u->farm_id,
            'unit_id' => $u->id, 'code' => $code, 'name' => $name, 'metric' => $metric, 'unit' => $label, 'expected_interval_seconds' => 600, 'status' => 'online', 'calibrated_on' => today()->subMonths(2), 'last_seen_at' => now()]);
        $temp = $dev($bh1, 'BH1-T1', 'BH1 temperature (centre)', 'temperature', '°C');
        $rh = $dev($bh1, 'BH1-RH1', 'BH1 humidity', 'humidity', '%');
        $nh3 = $dev($bh1, 'BH1-NH3', 'BH1 ammonia', 'ammonia', 'ppm');
        $flow = $dev($bh1, 'BH1-WF1', 'BH1 water flow', 'water_flow', 'l/h');
        $meter = $dev($bh1, 'BH1-WM1', 'BH1 water meter', 'water_meter', 'l', ['kind' => 'meter']);
        $fan = $dev($bh1, 'BH1-FAN', 'BH1 tunnel fans', 'fan', null, ['kind' => 'controller', 'controllable' => true, 'commands_need_approval' => true,
            'allowed_commands' => [['command' => 'fan_on'], ['command' => 'fan_off'], ['command' => 'set_speed', 'min' => 0, 'max' => 100]]]);
        $series = fn (callable $f, int $hours = 24, int $every = 10) => collect(range($hours * 60 / $every, 0))->map(fn ($k) => [$k * $every, $f($k * $every)])->all();
        $send = fn ($d, array $points, ?Carbon $received = null) => $readings->ingest($d->fresh(), array_map(fn ($p) => ['message_id' => "demo-{$d->code}-".now()->subMinutes($p[0])->format('YmdHi'),
            'value' => round($p[1], 2), 'measured_at' => now()->subMinutes($p[0])->toIso8601String()], $points), $received);
        $send($rh, $series(fn ($m) => 58 + 6 * sin($m / 230) + random_int(-10, 10) / 10));
        $send($nh3, $series(fn ($m) => 11 + 3 * sin($m / 300) + random_int(-5, 5) / 10));
        $send($flow, $series(fn ($m) => 780 + 260 * sin(($m + 300) / 230) + random_int(-30, 30)));
        $cum = 412000.0;
        $send($meter, $series(function ($m) use (&$cum) { $cum += 120 + random_int(0, 30); return $cum; }));
        $send($temp, $series(fn ($m) => $m <= 40 ? 33.2 + (40 - $m) / 40 : 27.5 + 2.5 * sin($m / 230) + random_int(-4, 4) / 10));
        // The layer house sensor went quiet two hours ago.
        $quiet = $dev($lh1, 'LH1-T1', 'LH1 temperature', 'temperature', '°C');
        $readings->ingest($quiet->fresh(), array_map(fn ($p) => ['message_id' => "demo-LH1-T1-{$p[0]}", 'value' => $p[1], 'measured_at' => now()->subMinutes($p[0])->toIso8601String()],
            $series(fn ($m) => 23 + random_int(-5, 5) / 10, 6)), now()->subMinutes(130));
        $Device::query()->whereKey($quiet->id)->update(['last_seen_at' => now()->subMinutes(130)]);
        $readings->sweepStale();
        $do = $dev($pond, 'POND1-DO', 'Pond 1 dissolved oxygen', 'dissolved_oxygen', 'mg/l');
        $send($do, $series(fn ($m) => 6.2 + 1.2 * sin($m / 230) + random_int(-2, 2) / 10));

        // The supervisor asks for the fans at 80 %: waiting for the manager.
        if (isset($this->u['supervisor'])) {
            app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Commands::class)->request($fan->fresh(), 'set_speed', ['value' => 80], $this->u['supervisor']->fresh(),
                'BH1 above 32 °C since mid-afternoon', null, 'demo-fan-80');
        }

        // Outside sources: weather, veterinary, funding and a market feed that is down.
        $Source = \App\Modules\Operations\Manufacturing\Models\Agriculture\Source::class;
        $saws = $Source::create(['company_id' => $this->companyId, 'code' => 'SAWS', 'name' => 'South African Weather Service warnings', 'kind' => 'weather', 'publisher' => 'SAWS', 'url' => 'https://www.weathersa.co.za', 'trust' => 'official', 'refresh_minutes' => 30]);
        $vet = $Source::create(['company_id' => $this->companyId, 'code' => 'DALRRD-VET', 'name' => 'Animal disease reports (DALRRD)', 'kind' => 'disease', 'publisher' => 'Department of Agriculture, Land Reform and Rural Development', 'url' => 'https://www.dalrrd.gov.za', 'trust' => 'official', 'refresh_minutes' => 360]);
        $lda = $Source::create(['company_id' => $this->companyId, 'code' => 'LIMPOPO-LDARD', 'name' => 'Limpopo Agriculture funding calls', 'kind' => 'government', 'publisher' => 'Limpopo Department of Agriculture and Rural Development', 'trust' => 'official', 'refresh_minutes' => 1440]);
        $mkt = $Source::create(['company_id' => $this->companyId, 'code' => 'MKT-FEED', 'name' => 'Poultry market prices', 'kind' => 'market', 'trust' => 'trusted', 'refresh_minutes' => 60]);
        $src = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Sources::class);
        foreach ([$saws, $vet, $lda] as $s) {
            $src->attempt($s, fn () => []);
        }
        $src->attempt($mkt, fn () => [['external_id' => 'broiler-live-'.today()->subDay()->format('Ymd'), 'kind' => 'price', 'title' => 'Live broilers R28.40/kg']], now()->subHours(5));
        $src->attempt($mkt->fresh(), fn () => throw new \RuntimeException('Connection timed out after 30 s'));

        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\RegionalWarnings::class)->apply($saws->fresh(), ['external_id' => 'SAWS-LP-HEAT-'.today()->format('md'), 'kind' => 'heat',
            'severity' => 'severe', 'latitude' => (float) $this->rivier->latitude + 0.1, 'longitude' => (float) $this->rivier->longitude - 0.1, 'radius_km' => 80,
            'starts_at' => today()->addDay()->setTime(11, 0)->toIso8601String(), 'ends_at' => today()->addDays(3)->setTime(18, 0)->toIso8601String(),
            'message' => 'Heatwave: persistently high temperatures of 38–41 °C expected over the Mopani district from tomorrow until Friday.']);
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Outbreaks::class)->report($vet->fresh(), ['external_id' => 'HPAI-LP-2026-017', 'disease' => 'Highly pathogenic avian influenza (H5N1)',
            'species' => 'poultry', 'status' => 'suspected', 'latitude' => (float) $this->rivier->latitude + 0.12, 'longitude' => (float) $this->rivier->longitude + 0.08, 'radius_km' => 10,
            'region' => 'Greater Tzaneen', 'reported_on' => today()->subDay()->toDateString(), 'body' => 'Suspected HPAI on a commercial layer farm; samples sent for confirmation. Movement controls within 10 km.']);
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Opportunities::class)->register($lda->fresh(), ['external_id' => 'LDARD-POULTRY-AQUA-2026', 'kind' => 'grant',
            'title' => 'Poultry and aquaculture expansion grant 2026/27', 'funder' => 'Limpopo Department of Agriculture and Rural Development', 'amount_max' => 750000,
            'opens_on' => today()->subWeek()->toDateString(), 'closes_on' => today()->addWeeks(5)->toDateString(), 'url' => 'https://www.lda.gov.za',
            'summary' => 'Co-funding for housing, cooling, water and biosecurity upgrades for commercial and emerging poultry and fish farmers in Limpopo.',
            'eligibility' => ['categories' => ['poultry', 'aquaculture'], 'provinces' => ['Limpopo'], 'requires' => ['SARS tax clearance', 'Proof of land tenure', 'Three-year business plan']]]);

        // The agents look at all of it; weight forecasts for the flock, and last flock's scored forecasts.
        $run = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Orchestrator::class)->run($this->companyId);
        $forecasts = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Forecasts::class);
        $forecasts->weight($flock->fresh(), $placed->copy()->addDays(28));
        $forecasts->weight($flock->fresh(), $placed->copy()->addDays(35));
        foreach ([[7, 0.18, 0.192], [14, 0.47, 0.49], [21, 0.93, 0.97], [28, 1.48, 1.52], [35, 2.06, 2.11]] as [$day, $pred, $actual]) {
            $f = \App\Modules\Operations\Manufacturing\Models\Agriculture\Forecast::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'metric' => 'avg_weight_kg',
                'target_on' => today()->subDays(60 - $day)->toDateString(), 'predicted' => $pred, 'lower' => $pred - 0.06, 'upper' => $pred + 0.06, 'unit' => 'kg',
                'model' => 'linear-trend', 'model_version' => '1.0', 'made_at' => today()->subDays(63 - $day), 'inputs' => ['flock' => 'BRO-2608-01 (previous)']]);
            $forecasts->resolve($f, $actual);
        }

        return \App\Modules\Operations\Manufacturing\Models\Agriculture\Unit::query()->count().' units, '.\App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::query()->count().' cycles, '
            .\App\Modules\Operations\Manufacturing\Models\Agriculture\Reading::query()->count().' readings, '
            .\App\Modules\Operations\Manufacturing\Models\Agriculture\Incident::query()->whereIn('status', ['open', 'acknowledged'])->count().' open incidents, '
            ."{$run['proposed']} recommendations (AI brief: {$run['ai']})";
    }

    /** A farm gateway for Rivierplaas, the packhouse line as a processing batch, and today's forecasts for every cycle. */
    private function productionMore(): string
    {
        $Unit = \App\Modules\Operations\Manufacturing\Models\Agriculture\Unit::class;
        $Device = \App\Modules\Operations\Manufacturing\Models\Agriculture\Device::class;
        $bh1 = $Unit::query()->where('code', 'BH1')->firstOrFail();
        $gw = $Device::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'unit_id' => $bh1->id, 'code' => 'RIV-GW1', 'name' => 'Rivierplaas poultry gateway',
            'kind' => 'gateway', 'status' => 'online', 'last_seen_at' => now(), 'expected_interval_seconds' => 300]);
        // The demo secret is issued and thrown away; issue a fresh one from Devices & commands to connect real hardware.
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Gateways::class)->issueSecret($gw);
        $type = \App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType::query()->where('code', 'PROCESSING')->firstOrFail();
        $line = $Unit::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'code' => 'PACKLINE', 'name' => 'Citrus packline', 'kind' => 'line',
            'production_type_id' => $type->id, 'attributes' => ['demo' => true]]);
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionCycles::class)->start($type, $line,
            ['code' => 'PACK-'.today()->format('ym').'-01', 'name' => 'Lemons export packing, this month', 'started_on' => today()->startOfMonth()->toDateString()], $this->u['owner']);
        $n = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Forecasts::class)->forecastAll($this->companyId);

        return "gateway RIV-GW1, packline batch, {$n} forecasts";
    }

    /** Phases 4 to 8 on the demo: what the Command Centre, intelligence and processing look like with them. */
    private function productionRoadmap(): string
    {
        config(['manufacturing.agriculture.ai_brief' => false]);
        $owner = $this->u['owner'];
        $M = '\\App\\Modules\\Operations\\Manufacturing\\Models\\Agriculture\\';
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionTypes::class)->installDefaults($this->companyId);
        $line = \App\Modules\Operations\Manufacturing\Models\Agriculture\Unit::query()->where('code', 'PACKLINE')->firstOrFail();
        \App\Modules\Operations\Manufacturing\Models\Agriculture\Threshold::create(['company_id' => $this->companyId, 'unit_id' => $line->id, 'metric' => 'cold_room_temperature',
            'max_value' => 8, 'severity' => 'critical', 'is_ccp' => true, 'corrective_action' => 'Move product to the backup cold room, check the compressor, re-test the core temperature before release.']);
        \App\Modules\Operations\Manufacturing\Models\Agriculture\Device::query()->whereIn('code', ['BH1-T1', 'BH1-RH1', 'BH1-NH3', 'BH1-WM1'])->update(['calibration_interval_days' => 90]);
        $community = \App\Modules\Operations\Manufacturing\Models\Agriculture\Source::create(['company_id' => $this->companyId, 'code' => 'FARMERS-GROUP', 'name' => 'Tzaneen poultry farmers group',
            'kind' => 'disease', 'trust' => 'community', 'refresh_minutes' => 1440]);
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Outbreaks::class)->report($community, ['external_id' => 'TPF-2026-10-20', 'disease' => 'Infectious coryza (reported by a member)',
            'species' => 'poultry', 'status' => 'suspected', 'latitude' => (float) $this->rivier->latitude - 0.05, 'longitude' => (float) $this->rivier->longitude + 0.03, 'radius_km' => 5,
            'body' => 'A member reports coughing and swollen faces in a backyard flock near the Letsitele road.']);
        $gazette = \App\Modules\Operations\Manufacturing\Models\Agriculture\Source::query()->where('code', 'LIMPOPO-LDARD')->firstOrFail();
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\RegulatoryNotices::class)->publish($gazette, ['external_id' => 'LDARD-NOTICE-2026-14',
            'title' => 'Poultry movement permits in Mopani district', 'body' => 'Until further notice, live poultry moved between properties in the Mopani district needs a movement permit from the state veterinarian.',
            'provinces' => ['Limpopo'], 'categories' => ['poultry']]);
        $types = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionTypes::class);
        $dairyType = \App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType::query()->where('code', 'DAIRY')->firstOrFail();
        $parlour = \App\Modules\Operations\Manufacturing\Models\Agriculture\Unit::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'code' => 'DAIRY1',
            'name' => 'Dairy herd camp', 'kind' => 'pen', 'production_type_id' => $dairyType->id, 'capacity' => 150, 'capacity_unit' => 'cows', 'attributes' => ['demo' => true]]);
        $cycles = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ProductionCycles::class);
        $herd = $cycles->start($dairyType, $parlour, ['code' => 'DAI-2610-01', 'name' => 'Jersey herd', 'opening_count' => 120, 'started_on' => today()->subDays(20)->toDateString(), 'breed' => 'Jersey'], $owner);
        for ($d = 14; $d >= 1; $d--) {
            $cycles->record($herd->fresh(), 'milk', ['quantity' => 2350 + random_int(-60, 60), 'cows_milked' => 118, 'scc' => $d === 1 ? 465000 : 210000 + random_int(-20000, 20000),
                'butterfat_pct' => 4.9, 'protein_pct' => 3.8, 'recorded_at' => today()->subDays($d)->setTime(6, 30), 'client_id' => "demo-milk-{$d}"], $owner);
        }
        $flock = \App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle::query()->where('code', 'BRO-2610-01')->firstOrFail();
        app(\App\Modules\Operations\Manufacturing\Services\Agriculture\ScenarioPlanner::class)->growOut($flock->type, $flock,
            ['placements' => [24000, 28000, 32000], 'days' => [32, 35, 38, 42], 'chick_price' => 8.5, 'feed_price_kg' => 8.4, 'live_price_kg' => 28, 'max_density_kg_m2' => 39], $owner, 'Next placement in BH1');
        $run = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Orchestrator::class)->run($this->companyId);
        $f = app(\App\Modules\Operations\Manufacturing\Services\Agriculture\Forecasts::class);
        $f->forecastAll($this->companyId);
        foreach ([[7, 0.17], [14, 0.47], [21, 0.95], [28, 1.51], [35, 2.15]] as [$day, $pred]) {
            $q = \App\Modules\Operations\Manufacturing\Models\Agriculture\Forecast::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'metric' => 'avg_weight_kg',
                'target_on' => today()->subDays(60 - $day)->toDateString(), 'predicted' => $pred + 0.02, 'lower' => $pred - 0.04, 'upper' => $pred + 0.08, 'unit' => 'kg',
                'model' => 'quadratic-trend', 'model_version' => '1.0', 'made_at' => today()->subDays(63 - $day), 'inputs' => ['flock' => 'BRO-2608-01 (previous)']]);
            $f->resolve($q, [7 => 0.192, 14 => 0.49, 21 => 0.97, 28 => 1.52, 35 => 2.11][$day]);
        }
        $champions = $f->chooseChampions($this->companyId);

        return "checklists, CCP on PACKLINE, dairy herd, {$run['proposed']} recommendations, champions: ".json_encode($champions);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Process 1 running on the demo farm: installed and published (the manager reviews what the owner
     * submits), a season started on three Boplaas fields — one limed after a low pH, one with its crop
     * plan approved and inputs on order, one still waiting for the lab.
     */
    private function seasonProcesses(): string
    {
        $W = \App\Modules\Workflow\Support\WorkflowPermissions::class;
        foreach ($W::all() as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $module = DB::table('security_modules')->where('code_name', 'workflow')->value('id');
        foreach (['manager', 'supervisor', 'finance'] as $k) {
            $this->u[$k]->givePermissionTo([$W::VIEW, $W::START]);
            if ($module) {
                DB::table('security_module_access')->updateOrInsert(['module_id' => $module, 'user_id' => $this->u[$k]->id], ['active' => 1, 'access_level' => 3, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $owner = $this->u['owner'];
        for ($round = 0; $round < 5; $round++) {
            $status = \App\Modules\Agri\Processes\CropSeasonProcess::install($owner);
            $waiting = collect($status)->filter(fn ($s) => $s !== 'published');
            if ($waiting->isEmpty() && count($status) === 4) {
                break;
            }
            app(\App\Modules\Workflow\Services\DefinitionService::class)->review(\App\Modules\Workflow\Models\WorkflowDefinition::query()->where('key', $waiting->keys()->first())->firstOrFail(),
                $this->u['manager'], true, 'Checked the steps and who they go to.');
        }

        // Planning next summer: this season's fields are already planted.
        $season = \App\Modules\Agri\Models\CropSeason::query()->where('status', 'open')->whereDate('starts_on', '>', today())->where('code', 'like', 'S%')->orderBy('starts_on')->firstOrFail();
        $opens = $season->starts_on->copy()->addMonths(3);
        $fields = FarmField::query()->where('farm_id', $this->boplaas->id)->where('status', 'active')->where('land_use_type', 'field')
            ->whereNotExists(fn ($q) => $q->from('farm_fields as p')->whereColumn('p.parent_field_id', 'farm_fields.id'))->orderBy('code')->limit(3)->get();
        Auth::setUser($this->u['manager']);
        $run = \App\Modules\Agri\Processes\CropSeasonProcess::startSeason($this->boplaas, $season, $this->u['manager'], ['planting_window_start' => $opens->toDateString(),
            'planting_window_end' => $opens->copy()->addDays(45)->toDateString(), 'replant_window_end' => $opens->copy()->addDays(75)->toDateString(), 'laboratory' => 'Nvirotek Labs', 'field_ids' => $fields->pluck('id')->all()]);
        $children = \App\Modules\Workflow\Models\WorkflowInstance::query()->where('parent_instance_id', $run->id)->orderBy('id')->get()->values();
        $tasks = app(\App\Modules\Workflow\Services\TaskService::class);
        $open = fn ($i, $node) => \App\Modules\Workflow\Models\WorkflowTask::query()->where('instance_id', $i->id)->where('node_key', $node)->where('status', 'open')->first()
            ?? throw new \RuntimeException("No open {$node} on {$i->reference}: ".DB::table('workflow_action_runs')->where('instance_id', $i->id)->whereNotNull('error')->value('error'));
        $lab = fn ($i, float $ph) => \App\Modules\Agri\Models\SoilTest::query()->findOrFail($i->fresh()->data['soil_test_id'])->update(['ph' => $ph, 'p_mg_kg' => 18, 'k_mg_kg' => 140,
            'recommendation' => $ph < 5.5 ? 'Apply 2 t/ha dolomitic lime before planting.' : 'No lime needed.']);
        if (isset($children[0])) {
            $lab($children[0], 5.0); // low pH: the lime task is waiting
        }
        if (isset($children[1])) {
            $f = $children[1];
            $lab($f, 6.4);
            $crop = $f->fresh()->data['crop_options'][0] ?? 'Soya';
            $tasks->decide($open($f, 'choose'), $this->u['manager'], 'completed', null, ['crop' => $crop]);
            Auth::setUser($owner);
            $tasks->decide($open($f, 'plan_ok'), $owner, 'approved', 'Rotation and budget fine.');
            Auth::setUser($this->u['manager']);
            $buy = \App\Modules\Workflow\Models\WorkflowInstance::query()->where('parent_instance_id', $f->id)->orderBy('id')->first();
            $buy && $tasks->decide($open($buy, 'prepare'), $this->u['manager'], 'completed', null, ['supplier' => 'Senwes', 'amount' => 86500, 'expected_delivery' => today()->addDays(6)->toDateString()]);
        }

        return $run->reference.' with '.$children->count().' field season(s)';
    }

    private function as(string $who): User
    {
        Auth::setUser($this->u[$who]);

        return $this->u[$who];
    }

    private function item(string $code, string $name, string $uom, float $cost, ?string $category = null): FinStockItem
    {
        $item = FinStockItem::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->first()
            ?? app(InventoryService::class)->createItem(['company_id' => $this->companyId, 'code' => $code, 'name' => $name, 'uom' => $uom, 'cost_method' => 'weighted_avg',
                'standard_cost' => $cost, 'is_active' => true, 'reorder_level' => 0]);
        if ($category) {
            \App\Modules\Agri\Models\AgriItemSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $this->companyId, 'stock_item_id' => $item->id], ['category' => $category]);
        }

        return $item;
    }

    private function existingItem(string $code): FinStockItem
    {
        return FinStockItem::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->firstOrFail();
    }

    private function party(string $name, string $kind = 'customer', array $extra = []): FinParty
    {
        return FinParty::withoutGlobalScopes()->where('company_id', $this->companyId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? app(PartyDirectory::class)->create(['company_id' => $this->companyId, 'kind' => $kind, 'name' => $name] + $extra, 'agri');
    }

    private function field(string $code): FarmField
    {
        return FarmField::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->firstOrFail();
    }

    private function plan(string $code): CropPlan
    {
        return CropPlan::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->firstOrFail();
    }

    private function machine(string $code): FarmMachine
    {
        return FarmMachine::withoutGlobalScopes()->where('company_id', $this->companyId)->where('fleet_number', $code)->firstOrFail();
    }

    private function cropType(string $code): int
    {
        return (int) CropType::query()->where('code', $code)->value('id');
    }

    private function species(string $code): int
    {
        return (int) LivestockSpecies::query()->where('code', $code)->value('id');
    }

    private function coldRoom(): FarmStore
    {
        return FarmStore::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('kind', 'cold_room')->firstOrFail();
    }

    private function pdf(string $title): string
    {
        return base64_encode("%PDF-1.4\n% Mkhaya Agri demo: {$title}\n");
    }

    /** A rectangle of $ha hectares centred on a point, as a GeoJSON polygon. */
    private function square(float $lat, float $lng, float $ha): array
    {
        $side = sqrt($ha) * 100;   // metres
        $dLat = $side / 2 / 110900;
        $dLng = $side / 2 / (111320 * cos(deg2rad($lat)));
        $ring = [[$lng - $dLng, $lat - $dLat], [$lng + $dLng, $lat - $dLat], [$lng + $dLng, $lat + $dLat], [$lng - $dLng, $lat + $dLat], [$lng - $dLng, $lat - $dLat]];

        return ['type' => 'Polygon', 'coordinates' => [array_map(fn ($p) => [round($p[0], 6), round($p[1], 6)], $ring)]];
    }

    /** A centre-pivot circle of $ha hectares. */
    private function circle(float $lat, float $lng, float $ha): array
    {
        $r = sqrt($ha * 10000 / M_PI);
        $ring = [];
        for ($i = 0; $i <= 32; $i++) {
            $a = 2 * M_PI * ($i % 32) / 32;
            $ring[] = [round($lng + $r * cos($a) / (111320 * cos(deg2rad($lat))), 6), round($lat + $r * sin($a) / 110900, 6)];
        }

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    // ── areas ────────────────────────────────────────────────────────────────────────────────

    private function map(): string
    {
        // Field centres as offsets (metres east, north) from the farmstead.
        $layout = [
            'L1' => [-1300, 900, 'pivot'], 'L2' => [600, 1100, 'square'], 'L3' => [-1300, -500, 'pivot'], 'L4' => [1000, -900, 'square'],
            'O1' => [500, 400, 'square'], 'K1' => [-900, -600, 'square'], 'K2' => [-300, 900, 'pivot'], 'H1' => [80, -60, 'square'],
        ];
        $n = 0;
        foreach ([$this->boplaas, $this->rivier] as $farm) {
            $lat = (float) $farm->latitude;
            $lng = (float) $farm->longitude;
            $fields = FarmField::withoutGlobalScopes()->where('farm_id', $farm->id)->whereNull('parent_field_id')->get();
            foreach ($fields as $f) {
                [$e, $nn, $shape] = $layout[$f->code] ?? [0, 0, 'square'];
                $fLat = $lat + $nn / 110900;
                $fLng = $lng + $e / (111320 * cos(deg2rad($lat)));
                $ha = max(0.2, (float) $f->area);
                $f->forceFill(['latitude' => round($fLat, 7), 'longitude' => round($fLng, 7),
                    'boundary' => $shape === 'pivot' ? $this->circle($fLat, $fLng, $ha) : $this->square($fLat, $fLng, $ha)])->saveQuietly();
                $n++;
            }
            // The farm boundary: a box round every field with a margin.
            $pts = $fields->flatMap(fn ($f) => $f->fresh()->boundary['coordinates'][0] ?? []);
            $minX = $pts->min(0) - 0.004;
            $maxX = $pts->max(0) + 0.004;
            $minY = $pts->min(1) - 0.004;
            $maxY = $pts->max(1) + 0.004;
            $farm->forceFill(['boundary' => ['type' => 'Polygon', 'coordinates' => [[[$minX, $minY], [$maxX, $minY], [$maxX, $maxY], [$minX, $maxY], [$minX, $minY]]]]])->saveQuietly();
        }
        $loc = function (Farm $farm, string $name, string $type, int $e, int $nn, ?string $notes = null) {
            $lat = (float) $farm->latitude + $nn / 110900;
            $lng = (float) $farm->longitude + $e / (111320 * cos(deg2rad((float) $farm->latitude)));
            FarmLocation::query()->firstOrCreate(['farm_id' => $farm->id, 'name' => $name], ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $farm->company_id,
                'location_type' => $type, 'latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'status' => 'active', 'notes' => $notes]);
        };
        $loc($this->boplaas, 'Werf en stoor', 'store', 0, 0, 'Farmstead, workshop and main store');
        $loc($this->boplaas, 'Graansilo\'s', 'store', 150, -120, '3 × 500 t silos');
        $loc($this->boplaas, 'Boorgat 1 (pivots)', 'borehole', -800, 200);
        $loc($this->boplaas, 'Damme — Rivierkant', 'dam', 200, 1700, 'Off-channel storage dam, 45 000 m³');
        $loc($this->rivier, 'Pakhuis', 'packhouse', 120, 60, 'Citrus packhouse and cold rooms');
        $loc($this->rivier, 'Kraal', 'kraal', -500, -200);
        $loc($this->rivier, 'Boorgat (boord)', 'borehole', 450, 150);
        $loc($this->rivier, 'Opstal', 'house', 0, 0);

        return "{$n} fields, 2 farm boundaries, 8 locations";
    }

    /**
     * Management zones on the demo fields (sectors on the pivots, strips on the square lands) and a
     * boundary history: every mapped field and both farms get their first version, and two lands
     * show a later correction, so the history and the area audit have something to show.
     */
    private function fieldMaps(): string
    {
        $maps = app(\App\Modules\Agri\Services\FieldMaps::class);
        $owner = $this->u['owner'];
        $mgr = $this->as('manager');
        $versions = 0;
        $zones = 0;
        foreach ([$this->boplaas, $this->rivier] as $farm) {
            $farm = $farm->fresh();
            if ($shape = \App\Modules\Agri\Support\Geo::polygon($farm->boundary)) {
                $ha = \App\Modules\Agri\Support\Geo::hectares($shape);
                $farm->forceFill(['mapped_area_ha' => $ha])->saveQuietly();
                if (! \App\Modules\Agri\Models\FieldBoundaryVersion::query()->where('farm_id', $farm->id)->whereNull('field_id')->exists()) {
                    $this->boundaryVersion($farm, null, 1, 'seeded', null, $shape, $owner, today()->subDays(420));
                    $versions++;
                }
            }
        }

        // Two lands were first mapped from a rough GPS walk and later corrected from the aerial photo.
        $corrected = ['L2' => 1.07, 'O1' => 0.93];
        foreach (FarmField::withoutGlobalScopes()->where('company_id', $this->companyId)->whereNotNull('boundary')->get() as $f) {
            $shape = \App\Modules\Agri\Support\Geo::polygon($f->boundary);
            if (! $shape) {
                continue;
            }
            $f->forceFill(['mapped_area_ha' => \App\Modules\Agri\Support\Geo::hectares($shape)])->saveQuietly();
            $farm = $f->farm_id === $this->boplaas->id ? $this->boplaas : $this->rivier;
            if (\App\Modules\Agri\Models\FieldBoundaryVersion::query()->where('field_id', $f->id)->exists()) {
                continue;
            }
            if (isset($corrected[$f->code])) {
                $rough = $this->scaled($shape, $corrected[$f->code]);
                $this->boundaryVersion($farm, $f, 1, 'seeded', null, $rough, $owner, today()->subDays(400));
                $this->boundaryVersion($farm, $f, 2, 'edited', $rough, $shape, $mgr, today()->subDays(35), 'Corrected from the 2026 aerial photo; the old line followed the road, not the fence.');
                $versions += 2;
            } else {
                $this->boundaryVersion($farm, $f, 1, 'seeded', null, $shape, $owner, today()->subDays(400));
                $versions++;
            }
        }

        // Zones: sectors on a centre pivot, strips across a square land, named the way agronomists do.
        $plan = [
            'L1' => ['sectors', [['North-east (deep red soil)', 'high', 'Hutton'], ['South (shallow)', 'low', 'Glenrosa'], ['West', 'medium', 'Clovelly']]],
            'L3' => ['sectors', [['Pivot east', 'high', 'Hutton'], ['Pivot west (wet corner)', 'medium', 'Avalon']]],
            'L2' => ['strips', [['Strip A', 'high', 'Avalon'], ['Strip B', 'medium', 'Avalon'], ['Strip C (stony)', 'low', 'Glenrosa']]],
            'L4' => ['strips', [['Bottom land', 'high', 'Westleigh'], ['Slope', 'medium', 'Clovelly'], ['Rocky ridge', 'exclude', null]]],
            'O1' => ['strips', [['Young block', 'medium', 'Oakleaf'], ['Mature block', 'high', 'Oakleaf']]],
            'K2' => ['sectors', [['Lucerne east', 'high', null], ['Lucerne west', 'medium', null]]],
        ];
        foreach ($plan as $code => [$how, $parts]) {
            $field = FarmField::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->first();
            $shape = $field ? \App\Modules\Agri\Support\Geo::polygon($field->boundary) : null;
            if (! $shape) {
                continue;
            }
            $shapes = $how === 'sectors' ? $this->sectors($shape, count($parts)) : $this->strips($shape, count($parts));
            foreach ($parts as $i => [$name, $class, $soil]) {
                $maps->saveZone($field, ['name' => $name, 'zone_class' => $class, 'soil_type' => $soil,
                    'notes' => $class === 'exclude' ? 'Not planted: rock outcrop. No inputs.' : null], $shapes[$i], $mgr);
                $zones++;
            }
        }

        return "{$zones} zones, {$versions} boundary versions";
    }

    /**
     * Five months of Sentinel-2 NDVI on every mapped land, as the daily fetch would have stored
     * it: a scene every five days, the cloudy ones remembered and skipped, each field's own
     * seasonal curve (maize just up, wheat ripening, lucerne cut and regrowing, the orchard
     * steady), zones above or below their field, the shallow south of L1 lagging behind, and the
     * mature citrus block falling sharply in the last pass — which raises the imagery flag and a
     * scouting check through the same import as any capture. Each stored scene has a small NDVI
     * picture drawn here with GD.
     */
    private function satellite(): string
    {
        $img = app(CropImagery::class);
        $geo = \App\Modules\Agri\Support\Geo::class;
        $source = (string) config('agri.satellite.providers.copernicus.source', 'Sentinel-2 L2A (CDSE)');
        \App\Modules\Agri\Models\SatelliteProvider::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->companyId, 'provider' => 'copernicus'],
            ['is_active' => false, 'settings' => \App\Modules\Agri\Models\SatelliteProvider::DEFAULTS]);

        // NDVI by days from today (negative = past), per land.
        $curves = [
            'L1' => fn (int $t) => $t < -12 ? 0.19 + 0.01 * sin($t / 7) : 0.19 + (0.36 - 0.19) * min(1, ($t + 12) / 10),            // maize, emerged 13 Oct
            'L2' => fn (int $t) => 0.27 + 0.03 * sin($t / 11),                                                                        // stubble, soybeans to come
            'L3' => fn (int $t) => 0.24 + 0.58 * exp(-(($t + 62) / 38) ** 2),                                                        // winter wheat, now ripening
            'L4' => fn (int $t) => 0.31 + 0.04 * sin($t / 15),                                                                        // veld, fallow land
            'K2' => fn (int $t) => 0.46 + 0.28 * fmod(($t + 400) / 35, 1.0),                                                         // lucerne, cut every 5 weeks
            'K1' => fn (int $t) => 0.33 + 0.08 * max(0, ($t + 40) / 40),                                                              // camp, greening up with spring
            'O1' => fn (int $t) => 0.66 + 0.03 * sin($t / 20),                                                                        // citrus, evergreen
        ];
        $zoneFactor = ['high' => 1.07, 'medium' => 1.0, 'low' => 0.88, 'other' => 0.95];
        $last = today()->subDays(2);
        $dates = collect(range(29, 0))->map(fn ($i) => $last->copy()->subDays(5 * $i));
        // Cloud over the farm per pass (the same pass covers every land): about one in five too cloudy.
        $cloud = $dates->mapWithKeys(fn ($d, $i) => [$d->toDateString() => $i === 29 || $i === 28 ? 3.0 : [2, 4, 64, 9, 1, 18, 3, 88, 6, 2, 41, 5, 12, 3, 97, 7, 2, 4, 71, 3, 26, 8, 2, 55, 4, 1, 6, 79, 3, 5][$i]]);

        $stored = $cloudy = $pictures = $flags = 0;
        foreach ($curves as $code => $curve) {
            $field = FarmField::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->first();
            $shape = $field ? $geo::polygon($field->boundary) : null;
            if (! $shape) {
                continue;
            }
            $zones = \App\Modules\Agri\Models\FieldZone::withoutGlobalScopes()->where('field_id', $field->id)->where('status', 'active')->where('zone_class', '!=', 'exclude')->orderBy('name')->get();
            $grid = $this->ndviGrid($shape, $zones);
            $bounds = $geo::bounds($shape);
            $have = FieldImagery::withoutGlobalScopes()->where('field_id', $field->id)->where('index', 'ndvi')->pluck('captured_on')->map(fn ($d) => $d->toDateString())->all();
            mt_srand(crc32($code));
            foreach ($dates as $n => $date) {
                $day = $date->toDateString();
                $t = (int) today()->diffInDays($date, false);
                $cl = (float) $cloud[$day];
                if (in_array($day, $have, true)) {
                    continue;   // a capture somebody posted for that day already
                }
                if ($cl > 30) {
                    \App\Modules\Agri\Models\SatelliteScene::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'farm_id' => $field->farm_id, 'field_id' => $field->id,
                        'provider' => 'copernicus', 'acquired_on' => $day, 'scene_ref' => "S2L2A:{$day}", 'cloud_pct' => $cl, 'status' => 'too_cloudy', 'bounds' => $bounds]);
                    $cloudy++;

                    continue;
                }
                $isLast = $n === $dates->count() - 1;
                $values = [];
                foreach ($zones as $z) {
                    $lag = $code === 'L1' && str_starts_with($z->name, 'South') ? 9 : 0;           // shallow soil: emerged later
                    $v = $curve($t - $lag) * ($zoneFactor[$z->zone_class] ?? 1.0) + (mt_rand(-10, 10) / 1000);
                    if ($code === 'O1' && $isLast && $z->zone_class === 'high') {
                        $v *= 0.66;                                                                // the mature block falls: blocked drip line
                    }
                    $values[$z->uuid] = round(max(0.05, min(0.95, $v)), 4);
                }
                $base = $curve($t) + (mt_rand(-8, 8) / 1000);
                $mean = $values ? array_sum(array_map(fn ($z) => $values[$z->uuid] * max(0.01, (float) $z->area_ha), $zones->all())) / max(0.01, $zones->sum(fn ($z) => max(0.01, (float) $z->area_ha))) : $base;
                $mean = round(max(0.05, min(0.95, $mean)), 4);
                $cells = $zones->map(function ($z) use ($values, $geo) {
                    $c = $geo::centroid($geo::polygon($z->boundary));

                    return ['id' => $z->uuid, 'name' => $z->name, 'value' => $values[$z->uuid], 'lat' => $c[1] ?? null, 'lng' => $c[0] ?? null];
                })->values()->all();
                $png = $this->ndviPng($grid, $values, $mean);
                $r = $img->import($field, ['index' => 'ndvi', 'captured_on' => $day, 'source' => $source, 'mean' => $mean,
                    'min' => round(max(0, $mean - 0.14 - mt_rand(0, 40) / 1000), 4), 'max' => round(min(1, $mean + 0.12 + mt_rand(0, 40) / 1000), 4),
                    'cloud_pct' => $cl, 'cells' => $cells ?: null, 'image_mime' => 'image/png', 'image_data' => base64_encode($png), 'client_id' => "S2L2A:{$day}",
                    // Only the last pass is compared for a drop here: earlier passes interleave with captures posted by hand.
                    'flag' => $isLast], null);
                \App\Modules\Agri\Models\SatelliteScene::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'farm_id' => $field->farm_id, 'field_id' => $field->id,
                    'provider' => 'copernicus', 'acquired_on' => $day, 'scene_ref' => "S2L2A:{$day}", 'cloud_pct' => $cl, 'status' => 'stored',
                    'imagery_id' => $r['imagery']->id, 'bounds' => $bounds, 'zones' => count($cells), 'has_image' => true]);
                $stored++;
                $pictures++;
                $flags += $r['flag'] ? 1 : 0;
            }
        }

        return "{$stored} scenes stored with pictures, {$cloudy} too cloudy, {$flags} flag(s)";
    }

    /** Which zone each pixel of a field's picture falls in (null outside the field, -1 inside but in no zone). */
    private function ndviGrid(array $shape, \Illuminate\Support\Collection $zones): array
    {
        $geo = \App\Modules\Agri\Support\Geo::class;
        [$w0, $s0, $e0, $n0] = $geo::bounds($shape);
        $k = cos(deg2rad(($s0 + $n0) / 2));
        $wm = ($e0 - $w0) * 111320 * $k;
        $hm = ($n0 - $s0) * 110574;
        $scale = 48 / max($wm, $hm, 1);
        $w = max(8, (int) round($wm * $scale));
        $h = max(8, (int) round($hm * $scale));
        $zoneShapes = $zones->map(fn ($z) => [$z->uuid, $geo::polygon($z->boundary)])->all();
        $cells = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $pt = [$w0 + ($x + 0.5) / $w * ($e0 - $w0), $n0 - ($y + 0.5) / $h * ($n0 - $s0)];
                if (! $geo::contains($shape, $pt)) {
                    $cells[$y][$x] = null;

                    continue;
                }
                $cells[$y][$x] = -1;
                foreach ($zoneShapes as [$uuid, $zs]) {
                    if ($zs && $geo::contains($zs, $pt)) {
                        $cells[$y][$x] = $uuid;
                        break;
                    }
                }
            }
        }

        return ['w' => $w, 'h' => $h, 'cells' => $cells];
    }

    /** A small NDVI picture: each pixel its zone's value (or the field mean) with a little texture, transparent outside the field. */
    private function ndviPng(array $grid, array $values, float $mean): string
    {
        $im = imagecreatetruecolor($grid['w'], $grid['h']);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $ramp = \App\Modules\Agri\Satellite\CopernicusProvider::RAMP;
        $colour = function (float $v) use ($ramp) {
            $v = max($ramp[0][0], min(end($ramp)[0], $v));
            for ($i = 1; $i < count($ramp); $i++) {
                if ($v <= $ramp[$i][0]) {
                    [$a, $ca] = $ramp[$i - 1];
                    [$b, $cb] = $ramp[$i];
                    $f = ($v - $a) / (($b - $a) ?: 1);
                    $ca = sscanf($ca, '#%02x%02x%02x');
                    $cb = sscanf($cb, '#%02x%02x%02x');

                    return array_map(fn ($p, $q) => (int) round($p + ($q - $p) * $f), $ca, $cb);
                }
            }

            return sscanf(end($ramp)[1], '#%02x%02x%02x');
        };
        foreach ($grid['cells'] as $y => $row) {
            foreach ($row as $x => $zone) {
                if ($zone === null) {
                    continue;
                }
                $v = ($zone !== -1 ? ($values[$zone] ?? $mean) : $mean) + sin($x * 0.9 + $y * 0.4) * 0.025 + (mt_rand(-20, 20) / 1000);
                [$r, $g, $b] = $colour($v);
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, 0));
            }
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function boundaryVersion(Farm $farm, ?FarmField $field, int $version, string $source, ?array $previous, array $shape, User $by, Carbon $at, ?string $reason = null): void
    {
        $geo = \App\Modules\Agri\Support\Geo::class;
        $v = \App\Modules\Agri\Models\FieldBoundaryVersion::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'field_id' => $field?->id,
            'version' => $version, 'source' => $source, 'boundary' => $shape, 'previous_boundary' => $previous,
            'area_ha' => $geo::hectares($shape), 'previous_area_ha' => $previous ? $geo::hectares($previous) : null,
            'recorded_area_ha' => $field ? $field->hectares() : Farm::hectares($farm->area !== null ? (float) $farm->area : null, $farm->area_unit),
            'reason' => $reason, 'changed_by' => $by->id]);
        $v->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    /** A shape grown or shrunk about its middle. */
    private function scaled(array $shape, float $factor): array
    {
        [$cx, $cy] = \App\Modules\Agri\Support\Geo::centroid($shape);
        $ring = array_map(fn ($c) => [round($cx + ($c[0] - $cx) * $factor, 7), round($cy + ($c[1] - $cy) * $factor, 7)], $shape['coordinates'][0]);

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    /** Pie slices of a (round) field, kept a little inside its edge. */
    private function sectors(array $shape, int $n): array
    {
        [$cx, $cy] = \App\Modules\Agri\Support\Geo::centroid($shape);
        $r = 0.0;
        foreach ($shape['coordinates'][0] as $c) {
            $r = max($r, hypot(($c[0] - $cx) * cos(deg2rad($cy)) * 111320, ($c[1] - $cy) * 110900));
        }
        $r *= 0.9;   // inside the polygonal circle's flats
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $ring = [[$cx, $cy]];
            for ($s = 0; $s <= 12; $s++) {
                $a = 2 * M_PI * ($i + $s / 12) / $n;
                $ring[] = [round($cx + $r * cos($a) / (111320 * cos(deg2rad($cy))), 7), round($cy + $r * sin($a) / 110900, 7)];
            }
            $ring[] = [$cx, $cy];
            $out[] = ['type' => 'Polygon', 'coordinates' => [$ring]];
        }

        return $out;
    }

    /** West-to-east strips of a (square) field, kept a few metres inside its edge. */
    private function strips(array $shape, int $n): array
    {
        [$x0, $y0, $x1, $y1] = \App\Modules\Agri\Support\Geo::bounds($shape);
        $mx = 5 / (111320 * cos(deg2rad($y0)));
        $my = 5 / 110900;
        [$x0, $x1, $y0, $y1] = [$x0 + $mx, $x1 - $mx, $y0 + $my, $y1 - $my];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $a = round($x0 + ($x1 - $x0) * $i / $n, 7);
            $b = round($x0 + ($x1 - $x0) * ($i + 1) / $n, 7);
            $out[] = ['type' => 'Polygon', 'coordinates' => [[[$a, $y0], [$b, $y0], [$b, $y1], [$a, $y1], [$a, $y0]]]];
        }

        return $out;
    }

    private function catalogue(): string
    {
        $owner = $this->u['owner'];
        $manager = $this->as('manager');
        foreach ([['HERB-ATR', 'L 7651', 'Atrazine', 'SC', '5', 'toxic', 12, 60, '2028-06-30'],
            ['FUNG-TEB', 'L 8432', 'Tebuconazole', 'EW', '4', 'toxic', 24, 28, '2026-11-15'],
        ] as [$code, $reg, $ai, $form, $hazard, $class, $reentry, $whp, $exp]) {
            $item = $this->existingItem($code);
            ChemicalProduct::query()->firstOrCreate(['company_id' => $this->companyId, 'stock_item_id' => $item->id], ['registration_no' => $reg, 'active_ingredient' => $ai,
                'formulation' => $form, 'hazard_class' => $hazard, 'storage_class' => $class, 'ppe' => 'Nitrile gloves, respirator (A2P2), coveralls, goggles',
                'handling' => 'Mix outdoors. Triple-rinse containers. Keep away from water sources.', 'reentry_hours' => $reentry, 'withholding_days' => $whp,
                'restricted' => false, 'registration_expires_on' => $exp, 'label_name' => "{$ai}-label.pdf", 'label_mime' => 'application/pdf', 'label_data' => $this->pdf("{$ai} label"),
                'sds_name' => "{$ai}-SDS.pdf", 'sds_mime' => 'application/pdf', 'sds_data' => $this->pdf("{$ai} SDS"), 'sds_revised_on' => '2025-03-01']);
        }
        $maize = $this->cropType('MAIZE-W');
        $n = 0;
        foreach ([[$maize, 'seed', 'SEED-MZ-7879', 'DKC 78-79 seed (80 000 seeds/ha)', 1, 'per_ha', 'bag', 0],
            [$maize, 'fertiliser', 'FERT-MAP', 'MAP at planting', 150, 'per_ha', 'kg', 0],
            [$maize, 'fertiliser', 'FERT-LAN', 'LAN top dressing (V6)', 100, 'per_ha', 'kg', 35],
            [$maize, 'chemical', 'HERB-ATR', 'Atrazine pre-emergence', 2, 'per_ha', 'l', 2],
            [$this->cropType('WHEAT'), 'fertiliser', 'FERT-LAN', 'LAN top dressing (tillering)', 150, 'per_ha', 'kg', 50],
        ] as [$type, $cat, $code, $desc, $rate, $basis, $unit, $days]) {
            $t = CropInputTemplate::create(['company_id' => $this->companyId, 'crop_type_id' => $type, 'production_method' => 'conventional', 'category' => $cat,
                'stock_item_id' => $this->existingItem($code)->id, 'description' => $desc, 'rate' => $rate, 'basis' => $basis, 'unit' => $unit, 'timing_days' => $days,
                'guidance_ref' => 'ARC-GCI production guideline 2025', 'status' => 'draft', 'created_by' => $manager->id]);
            app(InputPlanning::class)->approveTemplate($t, $owner);
            $n++;
        }
        GradingStandard::create(['company_id' => $this->companyId, 'crop_type_id' => $this->cropType('CITRUS'), 'name' => 'Lemons — CGA export standard', 'version' => 1, 'is_active' => true,
            'grades' => [['code' => 'Class 1', 'name' => 'Export Class 1', 'criteria' => 'Count 75–138, max 5% surface blemish'], ['code' => 'Class 2', 'name' => 'Export Class 2', 'criteria' => 'Max 15% blemish, wind scar allowed'],
                ['code' => 'Juice', 'name' => 'Juice / process', 'criteria' => 'Sound fruit not meeting class 2']],
            'checks' => [['check' => 'Juice content', 'unit' => '%', 'min' => 25], ['check' => 'Pulp temperature', 'unit' => '°C', 'min' => 7, 'max' => 12], ['check' => 'Imazalil residue', 'unit' => 'mg/kg', 'max' => 5]]]);

        return "2 chemical registrations, {$n} approved input rates, 1 grading standard";
    }

    private function budgets(): string
    {
        $b = app(FarmBudgets::class);
        $maize = FarmBudgetLine::withoutGlobalScopes()->where('farm_id', $this->boplaas->id)->where('description', 'Maize inputs')->firstOrFail();
        $rev = $b->revise($maize, 905000, 'Fertiliser up 9% since the budget; extra LAN pass after armyworm damage.', $this->as('manager'));
        $b->decideRevision($rev, true, 'Approved — keep the extra pass within the revised amount.', $this->as('owner'));
        $herd = FarmBudgetLine::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('description', 'Herd health programme')->firstOrFail();
        $b->revise($herd, 27500, 'Lumpy skin and brucellosis boosters for the new heifers.', $this->as('manager'));

        $line = fn (Farm $farm, string $cat, string $desc, float $amount, string $by) => FarmBudgetLine::create(['company_id' => $this->companyId, 'farm_id' => $farm->id,
            'category' => $cat, 'description' => $desc, 'amount' => $amount, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'created_by' => $this->u[$by]->id,
            'status' => 'draft']);
        $pack = $line($this->rivier, 'packaging', 'Lemon cartons, pallets and labels', 128000, 'manager');
        $b->submit($pack, $this->as('manager'));
        $b->decide($pack, true, $this->as('owner'));
        $fuel = $line($this->boplaas, 'fuel', 'Diesel — summer planting and spraying', 245000, 'finance');
        $b->submit($fuel, $this->as('finance'));
        $line($this->rivier, 'repairs', 'Packhouse line maintenance', 64000, 'manager');

        return '2 revisions (1 approved, 1 waiting), 3 new lines';
    }

    private function financePlans(): string
    {
        FarmLoan::create(['company_id' => $this->companyId, 'farm_id' => $this->boplaas->id, 'lender' => 'Land Bank', 'reference' => 'LB-PL-88213', 'principal' => 2400000,
            'annual_rate_pct' => 11.25, 'first_payment_on' => '2027-06-30', 'payment' => 1335000, 'frequency' => 'seasonal', 'instalments' => 2, 'is_active' => true]);
        FarmLoan::create(['company_id' => $this->companyId, 'farm_id' => $this->boplaas->id, 'lender' => 'John Deere Financial', 'reference' => 'JDF-6120M-0419', 'principal' => 1160000,
            'annual_rate_pct' => 10.5, 'first_payment_on' => '2025-11-01', 'payment' => 24930, 'frequency' => 'monthly', 'instalments' => 60, 'is_active' => true]);
        FarmLoan::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'lender' => 'Standard Bank Agri', 'reference' => 'SBA-ORCH-2291', 'principal' => 850000,
            'annual_rate_pct' => 11.75, 'first_payment_on' => '2026-03-31', 'payment' => 61500, 'frequency' => 'quarterly', 'instalments' => 20, 'is_active' => true]);
        foreach ([[$this->boplaas, 'Valley 8000 centre pivot — Kliprant (95 ha)', 4200000, '2027-03-15', 'committed'],
            [$this->boplaas, 'Replacement tractor (120 kW)', 1850000, '2027-07-01', 'planned'],
            [$this->rivier, 'Packhouse cold room 2 (40 pallets)', 960000, '2027-02-01', 'planned'],
            [$this->rivier, 'Orchard netting — block B', 520000, '2026-12-10', 'committed']] as [$farm, $desc, $amt, $on, $status]) {
            FarmCapexPlan::create(['company_id' => $this->companyId, 'farm_id' => $farm->id, 'description' => $desc, 'amount' => $amt, 'planned_on' => $on, 'status' => $status]);
        }
        OverheadRule::create(['company_id' => $this->companyId, 'farm_id' => $this->boplaas->id, 'name' => 'Farm admin and insurance', 'basis' => 'per_ha', 'rate' => 850,
            'valid_from' => '2026-01-01', 'is_active' => true]);
        OverheadRule::create(['company_id' => $this->companyId, 'farm_id' => null, 'name' => 'Head-office management fee', 'basis' => 'pct_direct', 'rate' => 6,
            'valid_from' => '2026-01-01', 'is_active' => true]);
        OverheadRule::create(['company_id' => $this->companyId, 'farm_id' => $this->rivier->id, 'name' => 'Packhouse overheads', 'basis' => 'per_ha', 'rate' => 1200,
            'valid_from' => '2026-03-01', 'is_active' => true]);
        $note = '3 loans, 4 capex plans, 3 overhead rules';
        // The month's farm journals sent to the external books (no API call: the export is a file the other side reads).
        try {
            $fi = app(FinanceIntegration::class);
            $x = $fi->export($this->companyId, 'sage_business_cloud', today()->subMonths(2)->startOfMonth()->toDateString(), today()->toDateString(), $this->as('finance'));
            $refs = collect($x['lines'])->pluck('external_ref')->unique()->values();
            $fi->acknowledge($x['export'], $refs->mapWithKeys(fn ($r, $i) => [$r => $i === 0 ? ['status' => 'failed', 'error' => 'Account 4510 is not mapped in Sage'] : ['status' => 'accepted', 'target_id' => 'SBC-'.(70100 + $i)]])->all());
            $note .= ", 1 ledger export ({$refs->count()} journals)";
        } catch (ValidationException) {
            // nothing to export yet
        }

        return $note;
    }

    private function irrigation(): string
    {
        $irr = app(Irrigation::class);
        $mgr = $this->as('manager');
        $pump = $this->machine('PMP-01');
        $mk = fn (string $field, string $code, string $name, float $ha, string $system, float $flow, float $kwh, ?int $pumpId = null, float $water = 0.85) => IrrigationZone::create([
            'company_id' => $this->companyId, 'farm_id' => $this->field($field)->farm_id, 'field_id' => $this->field($field)->id, 'code' => $code, 'name' => $name, 'area_ha' => $ha,
            'system' => $system, 'pump_machine_id' => $pumpId, 'flow_m3_per_hour' => $flow, 'water_cost_per_m3' => $water, 'energy_kwh_per_hour' => $kwh, 'energy_cost_per_kwh' => 2.38,
            'meter_ref' => "WM-{$code}", 'is_active' => true]);
        $z = [
            'L1' => $mk('L1', 'L1-PIVOT', 'Langkamp pivot', 120, 'centre_pivot', 210, 55),
            'L3' => $mk('L3', 'L3-PIVOT', 'Bo-land pivot', 60, 'centre_pivot', 120, 37),
            'O1A' => $mk('O1', 'O1-BLOCK-A', 'Lemons block A (micro)', 20, 'micro', 48, 15, $pump->id),
            'O1B' => $mk('O1', 'O1-BLOCK-B', 'Lemons block B (micro)', 25, 'micro', 55, 15, $pump->id),
            'K2' => $mk('K2', 'K2-SPRINKLER', 'Lucerne sprinklers', 40, 'sprinkler', 90, 22),
        ];
        $events = 0;
        foreach ([['L1', -9, 600, 600], ['L1', -6, 600, 640], ['L1', -3, 600, 590], ['L3', -8, 480, 470], ['O1A', -7, 240, 240], ['O1B', -7, 240, 250],
            ['O1A', -4, 240, 330], ['O1B', -4, 240, 240], ['K2', -5, 300, 300], ['O1A', -1, 240, 235]] as [$zone, $d, $planned, $actual]) {
            $zz = $z[$zone];
            $start = today()->addDays($d)->setTime(5, 0);
            $e = $irr->plan(FarmField::withoutGlobalScopes()->findOrFail($zz->field_id), ['zone_id' => $zz->id, 'planned_start' => $start, 'planned_minutes' => $planned], $mgr);
            $irr->record($e, ['actual_start' => $start, 'actual_minutes' => $actual, 'source' => $zone === 'L1' ? 'meter' : 'manual'], $this->as('supervisor'));
            $events++;
        }
        $mgr = $this->as('owner');   // Rivier has no farm manager: the owner plans its blocks
        foreach ([['L1', 1, 600], ['L3', 2, 480], ['O1A', 2, 240], ['O1B', 2, 240]] as [$zone, $d, $planned]) {
            $zz = $z[$zone];
            $irr->plan(FarmField::withoutGlobalScopes()->findOrFail($zz->field_id), ['zone_id' => $zz->id, 'planned_start' => today()->addDays($d)->setTime(5, 0), 'planned_minutes' => $planned], $mgr);
            $events++;
        }
        $skip = $irr->plan($this->field('K2'), ['zone_id' => $z['K2']->id, 'planned_start' => today()->subDays(2)->setTime(5, 0), 'planned_minutes' => 300], $mgr);
        $irr->skip($skip, '18 mm rain overnight');
        $leak = $irr->exception($this->field('O1')->id, $z['O1B']->id, 'leak', 'Lateral burst at row 14, block B — water pooling.', $this->u['supervisor']);
        $old = $irr->exception($this->field('L3')->id, $z['L3']->id, 'fault', 'Tower 4 stopped — alignment fault.', $this->u['supervisor']);
        $irr->resolve($old, 'Alignment switch replaced; pivot ran a full circle.', $mgr);
        // Probe readings: two weeks, daily, at 30 cm.
        $n = 0;
        foreach (['L1' => [31, -0.6], 'L3' => [22, -0.9], 'O1' => [27, -0.3], 'K2' => [24, -0.4]] as $f => [$start, $slope]) {
            $field = $this->field($f);
            for ($d = 14; $d >= 0; $d--) {
                $vwc = $start + $slope * (14 - $d) + (($d % 3 === 0) ? 4.5 : 0) + (($d * 7) % 5) * 0.3;
                $irr->moisture($field, ['read_at' => today()->subDays($d)->setTime(6, 0), 'depth_cm' => 30, 'vwc_pct' => round(max(9, $vwc), 1), 'source' => 'sensor',
                    'device_ref' => "PROBE-{$f}", 'zone_id' => ($z[$f] ?? $z[$f.'A'] ?? null)?->id], null);
                $n++;
            }
        }

        return '5 zones, '.$events.' events (+1 skipped), 2 exceptions + automatic over-application, '.$n.' moisture readings';
    }

    private function soil(): string
    {
        $cl = app(CropLifecycle::class);
        $mgr = $this->as('manager');
        $n = 0;
        foreach ([['L1', 6.1, 1.4, 28, 32, 180, 1450, 310, 22, 'Lime 1 t/ha dolomitic before next season; P adequate.'],
            ['L2', 5.4, 0.8, 14, 18, 120, 820, 190, 15, 'Acidic: 2 t/ha dolomitic lime. Raise P with 200 kg/ha MAP.'],
            ['L3', 6.4, 1.6, 31, 41, 210, 1600, 340, 25, 'No lime needed. Maintain K.'],
            ['L4', 5.2, 0.6, 9, 12, 95, 610, 150, 11, 'Low P and K, acidic. Lime 2.5 t/ha; build-up P before the new pivot.'],
            ['O1', 6.8, 1.9, 22, 35, 260, 1900, 420, 38, 'Leaf analysis advised in February; Zn spray at flush.'],
        ] as [$f, $ph, $oc, $n_, $p, $k, $ca, $mg, $ec, $rec]) {
            $field = $this->field($f);
            $cl->recordSoilTest($field, ['sampled_on' => today()->subDays(40 + $n * 3)->toDateString(), 'laboratory' => $f === 'O1' ? 'Bemlab, Somerset West' : 'Nviro Tech Labs, Bethlehem',
                'sample_ref' => sprintf('ST-26-%04d', 410 + $n), 'depth_cm' => 30, 'latitude' => $field->latitude, 'longitude' => $field->longitude, 'ph' => $ph,
                'organic_carbon_pct' => $oc, 'n_mg_kg' => $n_, 'p_mg_kg' => $p, 'k_mg_kg' => $k, 'ca_mg_kg' => $ca, 'mg_mg_kg' => $mg, 'ec_ms_m' => $ec,
                'other_results' => ['Zn_mg_kg' => 1.2 + $n * 0.3, 'S_mg_kg' => 8 + $n], 'recommendation' => $rec,
                'report_name' => "soil-{$f}.pdf", 'report_mime' => 'application/pdf', 'report_data' => $this->pdf("soil test {$f}")], $mgr);
            $n++;
        }

        return "{$n} soil tests with lab reports";
    }

    private function weather(): string
    {
        $w = app(WeatherIntelligence::class);
        $mgr = $this->as('manager');
        $rules = [
            ['farm_id' => null, 'name' => 'No spraying in wind over 15 km/h', 'activity' => 'spraying', 'conditions' => [['metric' => 'wind_speed', 'op' => '>', 'value' => 15]],
                'lookahead_hours' => 24, 'advice' => 'Move the spray job; drift risk above 15 km/h.', 'basis' => 'Label instructions and SA Act 36 of 1947 drift guidance', 'severity' => 'warning'],
            ['farm_id' => null, 'name' => 'No spraying with rain inside 6 hours', 'activity' => 'spraying', 'conditions' => [['metric' => 'rain_probability', 'op' => '>=', 'value' => 60]],
                'lookahead_hours' => 6, 'advice' => 'Wash-off risk; spray after the rain.', 'basis' => 'Product rain-fastness on the label', 'severity' => 'warning'],
            ['farm_id' => $this->rivier->id, 'crop_type_id' => $this->cropType('CITRUS'), 'name' => 'Frost on the lemons', 'activity' => 'frost', 'conditions' => [['metric' => 'temp_min', 'op' => '<', 'value' => 1]],
                'lookahead_hours' => 48, 'advice' => 'Run the micro-sprinklers from 02:00 and check the low corner of block B.', 'basis' => 'CRI frost guidance for citrus', 'severity' => 'critical'],
            ['farm_id' => null, 'name' => 'Heat stress — field crews', 'activity' => 'worker_heat', 'conditions' => [['metric' => 'temperature', 'op' => '>=', 'value' => 34]],
                'lookahead_hours' => 24, 'advice' => 'Start at 05:30, stop field work at 12:00, water and shade every hour.', 'basis' => 'OHS Act environmental regulations (heat)', 'severity' => 'warning'],
            ['farm_id' => $this->rivier->id, 'species_id' => $this->species('CHICKEN-L'), 'name' => 'Layer house heat', 'activity' => 'livestock_heat', 'conditions' => [['metric' => 'temperature', 'op' => '>=', 'value' => 32]],
                'lookahead_hours' => 24, 'advice' => 'Fans and foggers on from 10:00; check water lines.', 'basis' => 'SAPA layer welfare code', 'severity' => 'warning'],
        ];
        $made = [];
        foreach ($rules as $r) {
            $made[] = $w->saveRule($r, $mgr);
        }
        $owner = $this->as('owner');
        foreach (array_slice($made, 0, 4) as $rule) {
            $w->approveRule($rule, $owner);
        }
        foreach ([[$this->boplaas, 'wind', 'advisory', 6, 30, 'Yellow level 2: damaging winds 50–70 km/h over the eastern Free State tomorrow afternoon.', 'SAWS-FS-2026-1021-01'],
            [$this->rivier, 'heat', 'warning', 20, 72, 'Orange level 5: heatwave for the Lowveld — maximums of 38–40 °C persisting for three days.', 'SAWS-LP-2026-1021-04'],
            [$this->rivier, 'thunderstorm', 'advisory', 30, 8, 'Yellow level 2: severe thunderstorms with hail possible over Mopani district.', 'SAWS-LP-2026-1021-05']] as [$farm, $kind, $sev, $from, $hours, $msg, $ext]) {
            WeatherWarning::withoutGlobalScopes()->create(['company_id' => $this->companyId, 'farm_id' => $farm->id, 'provider' => 'saws', 'external_id' => $ext, 'kind' => $kind,
                'severity' => $sev, 'starts_at' => now()->addHours($from), 'ends_at' => now()->addHours($from + $hours), 'message' => $msg, 'fetched_at' => now()]);
        }

        return '5 rules (4 approved, 1 draft), 3 SAWS warnings';
    }

    private function market(): string
    {
        $owner = $this->u['manager'];
        $series = [
            ['White maize WM1', 'MAIZE-W', 'WM1', 'JSE SAFEX', 'Randfontein', 't', [3920, 3985, 4060, 4110, 4205, 4180, 4090, 4010, 3975, 4040, 4125, 4190]],
            ['Yellow maize YM1', 'MAIZE-W', 'YM1', 'JSE SAFEX', 'Randfontein', 't', [3850, 3900, 3975, 4020, 4080, 4060, 3990, 3940, 3905, 3960, 4010, 4075]],
            ['Wheat', 'WHEAT', 'B1', 'JSE SAFEX', 'Randfontein', 't', [5620, 5680, 5745, 5810, 5890, 5940, 5870, 5790, 5720, 5760, 5830, 5905]],
            ['Soybeans', 'SOY', null, 'JSE SAFEX', 'Randfontein', 't', [7650, 7720, 7840, 7980, 8120, 8060, 7930, 7860, 7790, 7880, 7990, 8075]],
            ['Lemons 15kg carton', 'CITRUS', 'Class 1', 'Joburg Market', 'Gauteng', 'carton', [128, 134, 141, 152, 166, 178, 171, 160, 148, 139, 145, 158]],
            ['Lemons 15kg carton', 'CITRUS', 'Class 1', 'Tshwane Market', 'Gauteng', 'carton', [122, 130, 137, 149, 160, 172, 168, 155, 143, 136, 140, 151]],
        ];
        $n = 0;
        foreach ($series as [$product, $type, $grade, $market, $region, $unit, $prices]) {
            foreach ($prices as $i => $p) {
                MarketPrice::create(['company_id' => $this->companyId, 'product' => $product, 'crop_type_id' => $this->cropType($type), 'grade' => $grade, 'market' => $market,
                    'region' => $region, 'price_date' => today()->subMonths(11 - $i)->startOfMonth()->addDays(14)->min(today())->toDateString(), 'price' => $p, 'currency' => 'ZAR', 'unit' => $unit,
                    'kind' => 'actual', 'source' => $market === 'JSE SAFEX' ? 'JSE commodity derivatives — spot close' : "{$market} daily prices", 'recorded_by' => $owner->id]);
                $n++;
            }
        }
        foreach ([['White maize WM1', 'MAIZE-W', 'WM1', 'JSE SAFEX', 't', 4260, '2027-07-15'], ['Soybeans', 'SOY', null, 'JSE SAFEX', 't', 8150, '2027-05-15']] as [$product, $type, $grade, $market, $unit, $p, $on]) {
            MarketPrice::create(['company_id' => $this->companyId, 'product' => $product, 'crop_type_id' => $this->cropType($type), 'grade' => $grade, 'market' => $market, 'region' => 'Randfontein',
                'price_date' => $on, 'price' => $p, 'currency' => 'ZAR', 'unit' => $unit, 'kind' => 'forecast', 'source' => 'SAFEX July/May futures', 'recorded_by' => $owner->id]);
            $n++;
        }
        $tiger = $this->party('Tiger Brands Milling', 'customer', ['category' => 'produce_buyer', 'credit_terms_days' => 30]);
        MarketRequest::create(['company_id' => $this->companyId, 'party_id' => $tiger->id, 'buyer_name' => $tiger->name, 'product' => 'White maize WM1', 'grade' => 'WM1',
            'quantity' => 800, 'unit' => 't', 'needed_by' => '2027-06-30', 'target_price' => 4150, 'status' => 'open']);
        MarketRequest::create(['company_id' => $this->companyId, 'buyer_name' => 'Fruit & Veg City Polokwane', 'product' => 'Lemons 15kg carton', 'grade' => 'Class 1',
            'quantity' => 1500, 'unit' => 'carton', 'needed_by' => today()->addDays(25)->toDateString(), 'target_price' => 160, 'status' => 'open']);
        MarketRequest::create(['company_id' => $this->companyId, 'buyer_name' => 'Senwes Bethlehem', 'product' => 'Soybeans', 'quantity' => 250, 'unit' => 't',
            'needed_by' => '2027-05-31', 'target_price' => 7950, 'status' => 'open']);

        return "{$n} market prices, 3 buyer requests";
    }

    private function crops(): string
    {
        $cl = app(CropLifecycle::class);
        $sup = $this->as('supervisor');
        $maize = $this->plan('MZ-26-L1');
        $wheat = $this->plan('WH-26-L3');
        $lemons = $this->plan('CIT-26-O1');
        $stages = 0;
        foreach ([[$wheat, 'flowering', '2026-08-20', 'Anthesis across the pivot.'], [$wheat, 'grain_fill', '2026-09-10', null], [$wheat, 'maturity', '2026-10-02', 'Hard dough, 14% moisture.'],
            [$wheat, 'harvest_ready', '2026-10-08', null], [$maize, 'emergence', '2026-10-13', 'Even stand, ±78 000 plants/ha.'], [$maize, 'vegetative', today()->subDay()->toDateString(), 'V3 — armyworm on the east edge.'],
            [$lemons, 'flowering', '2026-08-15', null], [$lemons, 'fruit_set', '2026-09-20', 'Good set on block A, lighter on B.'], [$lemons, 'maturity', '2026-10-05', 'Juice 31%, colour break.']] as [$plan, $stage, $on, $note]) {
            $cl->recordStage($plan, $stage, $on, $note, $sup);
            $stages++;
        }
        foreach ([[$this->cropType('MAIZE-W'), 'pest', 'high', null, 1, 7, 24], [$this->cropType('MAIZE-W'), 'pest', 'medium', 10, 2, 14, 48],
            [$this->cropType('WHEAT'), 'disease', 'medium', 5, 2, 14, 48], [null, 'weed', 'high', null, 2, 21, 72], [$this->cropType('CITRUS'), 'pest', 'medium', 2, 1, 7, 48]] as [$type, $kind, $sev, $pct, $count, $days, $hours]) {
            ScoutingThreshold::create(['company_id' => $this->companyId, 'crop_type_id' => $type, 'kind' => $kind, 'min_severity' => $sev, 'min_incidence_pct' => $pct, 'count' => $count,
                'within_days' => $days, 'escalate_after_hours' => $hours, 'is_active' => true]);
        }
        // Satellite NDVI on the pivots; the second wheat capture drops and raises a flag.
        $img = app(CropImagery::class);
        $mgr = $this->as('manager');
        $cells = fn (float $base, float $drop = 0) => collect(range(1, 6))->map(fn ($i) => ['id' => "c{$i}", 'value' => round($base - ($i === 4 ? $drop : 0) + ($i % 3) * 0.01, 3),
            'lat' => (float) $this->field('L3')->latitude + ($i - 3) * 0.001, 'lng' => (float) $this->field('L3')->longitude])->all();
        $img->import($this->field('L1'), ['index' => 'ndvi', 'captured_on' => '2026-10-05', 'source' => 'Sentinel-2', 'mean' => 0.21, 'min' => 0.12, 'max' => 0.30, 'cloud_pct' => 4], $mgr);
        $img->import($this->field('L1'), ['index' => 'ndvi', 'captured_on' => '2026-10-15', 'source' => 'Sentinel-2', 'mean' => 0.34, 'min' => 0.18, 'max' => 0.47, 'cloud_pct' => 8], $mgr);
        $img->import($this->field('O1'), ['index' => 'ndvi', 'captured_on' => '2026-10-10', 'source' => 'Sentinel-2', 'mean' => 0.68, 'min' => 0.51, 'max' => 0.79, 'cloud_pct' => 2], $mgr);
        $img->import($this->field('L3'), ['index' => 'ndvi', 'captured_on' => '2026-09-18', 'source' => 'Sentinel-2', 'mean' => 0.74, 'min' => 0.6, 'max' => 0.83, 'cloud_pct' => 3, 'cells' => $cells(0.74)], $mgr);
        $img->import($this->field('L3'), ['index' => 'ndvi', 'captured_on' => '2026-10-03', 'source' => 'Sentinel-2', 'mean' => 0.69, 'min' => 0.38, 'max' => 0.8, 'cloud_pct' => 6, 'cells' => $cells(0.70, 0.22)], $mgr);

        $sim = app(DecisionSimulator::class)->create($this->boplaas, ['name' => 'Kliprant: sunflower vs soybeans 2026/27', 'field_id' => $this->field('L4')->id, 'crop_type_id' => $this->cropType('SUNFLOWER'),
            'base' => ['hectares' => 95, 'planting_date' => '2026-12-01', 'yield_per_ha' => 2.1, 'yield_unit' => 't', 'price' => 8600, 'inputs_per_ha' => 6200, 'labour_hours_per_ha' => 3,
                'labour_rate' => 35, 'water_required_m3_per_ha' => 0, 'other_per_ha' => 1800, 'payment_days' => 30, 'days_to_maturity' => 130],
            'scenarios' => [['name' => 'Dry season (-25% yield)', 'changes' => ['yield_per_ha' => 1.6]], ['name' => 'Price up 10%', 'changes' => ['price' => 9460]],
                ['name' => 'Inputs +15%', 'changes' => ['input_cost_pct' => 15]]]], $mgr);
        app(HarvestPlanning::class)->commit($maize, $this->cropType('MAIZE-W'), $this->boplaas->id, $this->companyId, ['buyer' => 'VKB Bethlehem', 'channel' => 'silo_contract', 'quantity' => 600,
            'unit' => 't', 'grade' => 'WM1', 'deliver_on' => '2027-06-15', 'price_per_unit' => 4150], $mgr);
        app(HarvestPlanning::class)->commit($lemons, $this->cropType('CITRUS'), $this->rivier->id, $this->companyId, ['buyer' => 'Letaba Citrus Exporters', 'channel' => 'export', 'quantity' => 300,
            'unit' => 'bins', 'grade' => 'Class 1', 'deliver_on' => today()->addDays(21)->toDateString(), 'price_per_unit' => 1400], $mgr);

        return "{$stages} stage events, 5 scouting thresholds, 5 imagery captures (1 flag), 1 simulation, 2 harvest commitments";
    }

    private function livestock(): string
    {
        $owner = $this->u['owner'];
        $hm = $this->u['herdsman'];
        $intel = app(LivestockIntelligence::class);
        $stores = app(FarmStores::class);
        $luc = $this->item('FEED-LUC', 'Lucerne hay (small bales)', 'kg', 3.20, 'feed');
        $meal = $this->item('FEED-MAIZEMEAL', 'Maize meal (feed grade)', 'kg', 5.10, 'feed');
        $lick = $this->item('FEED-LICK', 'Protein lick 33%', 'kg', 9.50, 'feed');
        foreach ([[$luc, 4000, 3.20], [$meal, 2000, 5.10], [$lick, 600, 9.50]] as [$it, $q, $c]) {
            $stores->receive($this->rivier, $it, $q, $c, today()->subDays(12)->toDateString(), 'purchase', 'Tzaneen Voer delivery', $owner, "demo-more-feed-{$it->code}");
        }
        $weanRation = FeedRation::create(['company_id' => $this->companyId, 'name' => 'Weaner growth ration', 'species_id' => $this->species('CATTLE'), 'notes' => 'Lucerne-based, 6 kg/head/day', 'is_active' => true]);
        foreach ([[$luc, 60], [$meal, 30], [$lick, 10]] as [$it, $pct]) {
            $weanRation->items()->create(['company_id' => $this->companyId, 'stock_item_id' => $it->id, 'share_pct' => $pct]);
        }
        $layRation = FeedRation::create(['company_id' => $this->companyId, 'name' => 'Layer mash (peak lay)', 'species_id' => $this->species('CHICKEN-L'), 'notes' => '115 g/bird/day', 'is_active' => true]);
        $layRation->items()->create(['company_id' => $this->companyId, 'stock_item_id' => $this->existingItem('FEED-LAYER')->id, 'share_pct' => 100]);

        $group = fn (string $code) => LivestockGroup::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->firstOrFail();
        $weaners = $group('WEAN-26');
        $layers = $group('LAY-H1');
        $herd = $group('BONS-BR');
        $feeds = 0;
        Auth::setUser($hm);
        for ($d = 7; $d >= 1; $d--) {
            $on = today()->subDays($d)->toDateString();
            $intel->feed($this->rivier, $weaners, null, ['ration_id' => $weanRation->id, 'fed_on' => $on, 'quantity_kg' => 48, 'client_id' => (string) \Illuminate\Support\Str::uuid()], $hm);
            $intel->feed($this->rivier, $layers, null, ['ration_id' => $layRation->id, 'fed_on' => $on, 'quantity_kg' => 262, 'client_id' => (string) \Illuminate\Support\Str::uuid()], $hm);
            $feeds += 2;
            $lot = $intel->produce($this->rivier, null, $layers, ['product' => 'eggs', 'recorded_on' => $on, 'quantity' => 66 + ($d % 3), 'unit' => 'tray', 'grade' => 'Large'], $hm);
            if ($d >= 3) {
                $intel->sellProduction($lot, round((66 + ($d % 3)) * 62.0, 2), $on, $this->as('owner'), 'bank');   // Rivier is run by the owner
                Auth::setUser($hm);
            }
        }
        // Lick for the breeding herd in the veld camp.
        $intel->feed($this->rivier, $herd, null, ['stock_item_id' => $lick->id, 'fed_on' => today()->subDays(3)->toDateString(), 'quantity_kg' => 25, 'client_id' => (string) \Illuminate\Support\Str::uuid()], $hm);
        $feeds++;

        foreach ([['CATTLE', 'Lumpy skin disease — annual', 'Lumpyvax', 'VAC-LSD', 'all', 180, 365, 0], ['CATTLE', 'Brucellosis S19 — heifers', 'Brucella S19', null, 'female', 120, null, 0],
            ['CATTLE', 'Anthrax and botulism', 'Onderstepoort Bovine 4-in-1', null, 'all', 180, 365, 21], ['SHEEP', 'Pulpy kidney (enterotoxaemia)', 'Pulpyvax', null, 'all', 60, 365, 21],
            ['CHICKEN-L', 'Newcastle disease booster', 'Avinew', null, 'all', 70, 90, 0]] as [$sp, $name, $prod, $item, $applies, $first, $repeat, $wd]) {
            VaccinationProgramme::create(['company_id' => $this->companyId, 'species_id' => $this->species($sp), 'name' => $name, 'product' => $prod,
                'stock_item_id' => $item ? $this->existingItem($item)->id : null, 'applies_to' => $applies, 'first_at_age_days' => $first, 'repeat_every_days' => $repeat,
                'withdrawal_days' => $wd, 'is_active' => true]);
        }
        $ls = app(Livestock::class);
        $k2 = $this->field('K2');
        $moved = 0;
        foreach (['RV-C002', 'RV-C004', 'RV-C006', 'RV-C008'] as $tag) {
            $a = Animal::withoutGlobalScopes()->where('company_id', $this->companyId)->where('tag_number', $tag)->where('status', 'active')->first();
            if ($a) {
                $ls->move($a, null, $k2, today()->subDays(4)->toDateString(), 'Close to calving — onto the lucerne camp', $hm);
                $moved++;
            }
        }
        $back = Animal::withoutGlobalScopes()->where('company_id', $this->companyId)->where('tag_number', 'RV-C002')->first();
        if ($back && $back->status === 'active') {
            $ls->move($back->fresh(), null, $this->field('K1'), today()->subDay()->toDateString(), 'Not yet springing — back to the veld', $hm);
            $moved++;
        }

        return "2 rations, {$feeds} feedings, 7 egg lots (5 sold), 5 vaccination programmes, {$moved} movements";
    }

    private function packhouse(): string
    {
        $owner = $this->u['owner'];
        $sup = $this->u['supervisor'];
        $inv = app(FarmInventory::class);
        $cold = $inv->addStore($this->rivier, ['kind' => 'cold_room', 'name' => 'Koelkamer 1', 'capacity' => 400, 'capacity_unit' => 'bins']);
        $cold->forceFill(['temperature_min' => 8, 'temperature_max' => 11, 'humidity_min' => 85, 'humidity_max' => 95, 'sensor_ref' => 'RIV-CR1'])->save();
        $inv->addStore($this->rivier, ['kind' => 'packhouse', 'name' => 'Pakhuis dispatch bay', 'capacity' => 60, 'capacity_unit' => 'pallets']);
        $inv->addStore($this->boplaas, ['kind' => 'silo', 'name' => 'Silo 1 (wheat)', 'capacity' => 500, 'capacity_unit' => 't']);
        $inv->addStore($this->boplaas, ['kind' => 'chemical_store', 'name' => 'Chemical store', 'restricted' => true, 'allowed_storage_classes' => ['toxic', 'corrosive', 'general']]);

        $lemonsBin = $this->existingItem('FRUIT-LEMON');
        $c1 = $this->item('FRUIT-LEMON-C1', 'Lemons Class 1 (graded bins)', 'bin', 0, 'produce');
        $c2 = $this->item('FRUIT-LEMON-C2', 'Lemons Class 2 (graded bins)', 'bin', 0, 'produce');
        $juice = $this->item('FRUIT-LEMON-JUICE', 'Lemons — juice grade (bins)', 'bin', 0, 'produce');
        $carton = $this->item('PACK-LEMON-15', 'Lemons Class 1 — 15 kg carton', 'carton', 0, 'produce');
        $box = $this->item('PKG-CARTON-15', 'Telescopic carton 15 kg (printed)', 'each', 9.80, 'packaging');
        app(FarmStores::class)->receive($this->rivier, $box, 5000, 9.80, today()->subDays(10)->toDateString(), 'purchase', 'Mpact Polokwane — cartons', $owner, 'demo-more-cartons');

        // Pick block B into the cold room.
        $plan = $this->plan('CIT-26-O1');
        $h = app(Harvests::class)->record($plan, ['harvested_on' => today()->subDays(6)->toDateString(), 'quantity' => 260, 'unit' => 'bins', 'destination' => 'store',
            'stock_item_id' => $lemonsBin->id, 'store_id' => $cold->id, 'unit_value' => 1350, 'rejected_quantity' => 4, 'measuring_method' => 'bin_count',
            'inspection_outcome' => 'passed', 'inspection_note' => 'Pulp temp 18 °C on intake; no decay seen.', 'storage_location' => 'Row A1–A6', 'vehicle_ref' => 'Trailer T-3'], $sup);
        $lot = ProduceLot::withoutGlobalScopes()->where('harvest_record_id', $h->id)->firstOrFail();
        $standard = GradingStandard::query()->where('name', 'Lemons — CGA export standard')->first();

        $ph = app(PostHarvest::class);
        $grade = $ph->operate($this->rivier, 'grading', [['lot' => $lot, 'quantity' => 220]], [
            ['product' => 'Lemons', 'grade' => 'Class 1', 'quantity' => 150, 'unit' => 'bins', 'stock_item_id' => $c1->id, 'store_id' => $cold->id, 'location' => 'Row B1–B4'],
            ['product' => 'Lemons', 'grade' => 'Class 2', 'quantity' => 52, 'unit' => 'bins', 'stock_item_id' => $c2->id, 'store_id' => $cold->id, 'location' => 'Row B5'],
            ['product' => 'Lemons', 'grade' => 'Juice', 'quantity' => 12, 'unit' => 'bins', 'stock_item_id' => $juice->id, 'store_id' => $cold->id, 'location' => 'Row C1'],
        ], [], ['performed_on' => today()->subDays(5)->toDateString(), 'standard_id' => $standard?->id, 'loss_quantity' => 6, 'loss_reason' => 'Decay and wind-scar culls',
            'workers' => 14, 'labour_minutes' => 14 * 480, 'labour_cost' => 14 * 8 * 28.79,
            'quality_checks' => [['check' => 'Juice content', 'value' => 31], ['check' => 'Pulp temperature', 'value' => 9.5], ['check' => 'Imazalil residue', 'value' => 2.1]],
            'notes' => 'Line speed 9 bins/h.'], $sup);
        $class1 = ProduceLot::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('grade', 'Class 1')->where('product', 'Lemons')->latest('id')->firstOrFail();
        $pack = $ph->operate($this->rivier, 'packing', [['lot' => $class1->fresh(), 'quantity' => 100]], [
            ['product' => 'Lemons 15kg carton', 'grade' => 'Class 1', 'quantity' => 2600, 'unit' => 'carton', 'stock_item_id' => $carton->id, 'store_id' => $cold->id,
                'location' => 'Pallets P01–P40', 'pallet_ref' => 'RV-P-0001..0040', 'best_before' => today()->addDays(55)->toDateString()],
        ], [['stock_item_id' => $box->id, 'quantity' => 2600]], ['performed_on' => today()->subDays(3)->toDateString(), 'loss_quantity' => 0, 'workers' => 18,
            'labour_minutes' => 18 * 480, 'labour_cost' => 18 * 8 * 28.79, 'quality_checks' => [['check' => 'Carton weight', 'value' => 15.2, 'ok' => true], ['check' => 'Label and PUC code', 'ok' => true]]], $sup);
        // A juice lot that failed a re-check: quarantined then written off.
        $juiceLot = ProduceLot::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('grade', 'Juice')->latest('id')->first();
        if ($juiceLot) {
            $ph->move($juiceLot->fresh(), null, 'Row C2 (overflow)', $sup);
        }

        return "4 stores, harvest lot {$lot->code}, grading run {$grade->reference}, packing run {$pack->reference}";
    }

    private function storage(): string
    {
        $ph = app(PostHarvest::class);
        $cold = $this->coldRoom();
        $sup = $this->u['supervisor'];
        $n = 0;
        for ($h = 72; $h >= 6; $h -= 6) {
            $t = 9.2 + (($h / 6) % 4) * 0.35;
            $hum = 90 + (($h / 6) % 3);
            $ph->reading($cold, ['recorded_at' => now()->subHours($h), 'temperature' => round($t, 1), 'humidity' => $hum], $sup);
            $n++;
        }
        $bad = $ph->reading($cold, ['recorded_at' => now()->subHours(40)->addMinutes(20), 'temperature' => 13.4, 'humidity' => 81], $sup);
        $ph->correct($bad, 'Door seal on CR1 replaced; doors kept shut during loading; back to 9.8 °C after 50 minutes.', $this->as('manager'));
        $ph->reading($cold, ['recorded_at' => now()->subHours(2), 'temperature' => 11.6, 'humidity' => 88], $sup);   // open: someone still needs to act
        // The cold room's sensor through the integration gateway (twice: the second is a replay).
        $int = app(Integrations::class);
        $int->receive($this->companyId, 'sensor_gateway', 'RIV-CR1-'.now()->subHour()->format('YmdH'), ['sensor' => 'RIV-CR1', 'temperature' => 9.9, 'humidity' => 91,
            'recorded_at' => now()->subHour()->toDateTimeString()], null, $this->rivier->id);
        $int->receive($this->companyId, 'sensor_gateway', 'RIV-CR1-'.now()->subHour()->format('YmdH'), ['sensor' => 'RIV-CR1', 'temperature' => 9.9, 'humidity' => 91,
            'recorded_at' => now()->subHour()->toDateTimeString()], null, $this->rivier->id);
        $int->receive($this->companyId, 'sensor_gateway', 'BOP-SILO1-'.now()->format('YmdH'), ['sensor' => 'BOP-SILO1', 'temperature' => 24.0], null, $this->boplaas->id);   // unknown sensor: fails and waits

        return ($n + 3).' readings (2 out of range, 1 corrected), 3 sensor messages';
    }

    private function sales(): string
    {
        $disp = app(ProduceDispatches::class);
        $mgr = $this->as('manager');
        $freshmark = $this->party('Freshmark (Shoprite) Centurion DC', 'customer', ['category' => 'produce_buyer', 'credit_terms_days' => 30, 'address' => 'Hennops Park, Centurion']);
        $order = $disp->order($this->rivier, ['party_id' => $freshmark->id, 'deliver_on' => today()->subDays(1)->toDateString(), 'delivery_address' => 'Freshmark DC, Hennops Park, Centurion',
            'customer_ref' => 'FM-PO-4410923'], [['product' => 'Lemons 15kg carton', 'grade' => 'Class 1', 'quantity' => 1200, 'unit' => 'carton', 'price' => 168]], $mgr);
        $picks = $disp->pickList($order);
        $items = collect($picks)->flatMap(fn ($l) => collect($l['picks'])->map(fn ($p) => ['order_line_id' => $l['line_id'], 'lot_id' => $p['lot_id'], 'quantity' => $p['quantity']]))->values()->all();
        if (! $items) {
            throw new \RuntimeException('No packed lemons available to pick for the order.');
        }
        $load = $disp->pick($order, $items, ['vehicle' => 'LP 44 RT GP (reefer)', 'driver' => 'Themba Maluleke'], $this->as('supervisor'));
        $contents = $load->packages()->with('contents')->get()->flatMap->contents;
        $load = $disp->verifyLoad($load, $contents->mapWithKeys(fn ($c) => [$c->id => (float) $c->qty])->all(), 'SEAL-778201', $this->as('manager'));
        $shipment = $disp->dispatch($load, $this->as('manager'));
        $shipment = $disp->deliver($shipment, $contents->mapWithKeys(fn ($c) => [$c->id => ['accepted' => (float) $c->qty - 24, 'rejected' => 24,
            'rejection_reason' => 'Soft fruit and green-mould in 24 cartons on pallet 3', 'rejected_to' => 'returned']])->all(),
            ['received_by' => 'K. Pillay (Freshmark receiving)', 'delivered_on' => today()->toDateString(), 'note' => 'Pulp temp 10.1 °C on arrival.'], $this->as('manager'));

        // A second order, open, waiting to be picked.
        $spar = $this->party('SPAR Northern DC (Polokwane)', 'customer', ['category' => 'produce_buyer', 'credit_terms_days' => 30]);
        $disp->order($this->rivier, ['party_id' => $spar->id, 'deliver_on' => today()->addDays(3)->toDateString(), 'customer_ref' => 'SPAR-N-77102'],
            [['product' => 'Lemons 15kg carton', 'grade' => 'Class 1', 'quantity' => 600, 'unit' => 'carton', 'price' => 162]], $mgr);
        // And a grain order from Boplaas to the co-op.
        $vkb = $this->party('VKB Landbou (Bethlehem)', 'customer', ['category' => 'produce_buyer', 'credit_terms_days' => 14]);
        $disp->order($this->boplaas, ['party_id' => $vkb->id, 'deliver_on' => today()->addDays(7)->toDateString(), 'customer_ref' => 'VKB-GR-2026-311'],
            [['product' => 'Wheat', 'grade' => 'B1', 'quantity' => 150, 'unit' => 't', 'price' => 5150]], $mgr);

        return "orders {$order->number} (delivered, 24 cartons returned) + 2 open; load {$load->number}, shipment {$shipment->number}";
    }

    private function contracts(): string
    {
        $sales = app(FarmSales::class);
        $mgr = $this->as('manager');
        $letaba = $this->party('Letaba Citrus Exporters', 'customer', ['category' => 'produce_buyer', 'credit_terms_days' => 21]);
        $standard = GradingStandard::query()->where('name', 'Lemons — CGA export standard')->first();
        $c = $sales->contract($this->companyId, ['farm_id' => $this->rivier->id, 'party_id' => $letaba->id, 'crop_type_id' => $this->cropType('CITRUS'), 'product' => 'Lemons 15kg carton',
            'quantity' => 6000, 'unit' => 'carton', 'tolerance_pct' => 5, 'windows' => [['from' => today()->toDateString(), 'to' => today()->addDays(30)->toDateString(), 'quantity' => 3000],
                ['from' => today()->addDays(31)->toDateString(), 'to' => today()->addDays(60)->toDateString(), 'quantity' => 3000]],
            'standard_id' => $standard?->id, 'accepted_grades' => ['Class 1'], 'quality_spec' => 'CGA export Class 1, count 88–113, PHI met', 'pricing' => 'fixed', 'price' => 172, 'currency' => 'ZAR',
            'packaging' => '15 kg telescopic carton, 80 per pallet', 'transport' => 'buyer', 'rejection_rules' => 'Rejections at intake within 24 h, with photos',
            'dispute_procedure' => 'PPECB inspection is final'], $mgr);
        $sales->setStatus($c, 'active', $this->as('owner'));
        $sales->amend($c->fresh(), ['price' => 176], 'Exchange rate moved; buyer agreed R4 more per carton for the second window.', $mgr = $this->as('manager'));
        $tiger = $this->party('Tiger Brands Milling', 'customer', ['category' => 'produce_buyer', 'credit_terms_days' => 30]);
        $sales->contract($this->companyId, ['farm_id' => $this->boplaas->id, 'party_id' => $tiger->id, 'crop_type_id' => $this->cropType('MAIZE-W'), 'product' => 'White maize WM1',
            'quantity' => 800, 'unit' => 't', 'tolerance_pct' => 10, 'windows' => [['from' => '2027-06-01', 'to' => '2027-06-30', 'quantity' => 500], ['from' => '2027-07-01', 'to' => '2027-07-31', 'quantity' => 300]],
            'accepted_grades' => ['WM1', 'WM2'], 'pricing' => 'formula', 'formula' => ['market' => 'JSE SAFEX', 'product' => 'White maize WM1', 'adjust' => -85, 'lookback_days' => 5],
            'currency' => 'ZAR', 'transport' => 'farm', 'packaging' => 'Bulk, delivered to Tiger Milling Bethlehem'], $mgr);
        $lot = ProduceLot::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('grade', 'Class 1')->where('product', 'Lemons')->where('status', 'available')->first();
        $note = '2 contracts (1 active with a proposed amendment, 1 draft)';
        if ($lot) {
            $sales->list($this->rivier, ['product' => 'Lemons', 'grade' => 'Class 1', 'quantity' => min(40, (float) $lot->remaining), 'unit' => 'bins', 'availability' => 'in_stock',
                'lot_ids' => [$lot->id], 'asking_price' => 1450, 'currency' => 'ZAR'], $this->as('owner'));   // Rivier's lots: the owner runs Rivier
            $this->as('manager');
            $note .= ', 2 listings';
        }
        $sales->list($this->boplaas, ['product' => 'White maize WM1', 'grade' => 'WM1', 'quantity' => 400, 'unit' => 't', 'availability' => 'forecast',
            'crop_plan_id' => $this->plan('MZ-26-L1')->id, 'asking_price' => 4200, 'currency' => 'ZAR'], $mgr);

        return $note;
    }

    private function recall(): string
    {
        $lot = ProduceLot::withoutGlobalScopes()->where('farm_id', $this->rivier->id)->where('grade', 'Class 2')->whereIn('status', ProduceLot::LIVE)->latest('id')->first();
        if (! $lot) {
            throw new \RuntimeException('No Class 2 lemon lot in the cold room to recall (fill the packhouse area first).');
        }
        $r = app(PostHarvest::class)->recall($lot, 'Lab result: imazalil residue 6.2 mg/kg on sample from this lot, above the 5 mg/kg MRL.', 'medium', $this->as('manager'));

        return "recall {$r->number} on lot {$lot->code}";
    }

    private function outgrowers(): string
    {
        $og = app(Outgrowers::class);
        $mgr = $this->as('owner');   // Rivier has no farm manager: the owner runs its outgrower scheme
        $cold = $this->coldRoom();
        $lemons = $this->existingItem('FRUIT-LEMON');
        $a = $og->register($this->rivier, ['name' => 'Mahlangu Family Trust', 'code' => 'GR-001', 'phone' => '0827741120', 'bank_name' => 'FNB', 'bank_account' => '62841177230',
            'location' => 'Mafarana, Tzaneen', 'latitude' => -23.795, 'longitude' => 30.442, 'hectares' => 12], $mgr);
        $b = $og->register($this->rivier, ['name' => 'Tshivhase Citrus Co-operative', 'code' => 'GR-002', 'phone' => '0763320918', 'location' => 'Thohoyandou',
            'latitude' => -22.951, 'longitude' => 30.484, 'hectares' => 25], $mgr);
        $og->register($this->rivier, ['name' => 'Nkuna Small Growers', 'code' => 'GR-003', 'location' => 'Nkowankowa', 'latitude' => -23.889, 'longitude' => 30.290, 'hectares' => 6], $mgr);
        $ca = $og->contract($a, ['crop_type_id' => $this->cropType('CITRUS'), 'product' => 'Lemons', 'hectares' => 12, 'expected_quantity' => 420, 'unit' => 'bins', 'price' => 1150,
            'grade_prices' => ['Class 1' => 1250, 'Class 2' => 900], 'advance_limit' => 60000], $mgr);
        $cb = $og->contract($b, ['crop_type_id' => $this->cropType('CITRUS'), 'product' => 'Lemons', 'hectares' => 25, 'expected_quantity' => 800, 'unit' => 'bins', 'price' => 1100,
            'advance_limit' => 100000], $mgr);
        // Drafted by the owner, approved by the finance officer: someone else approves a grower contract.
        $owner = $this->as('finance');
        $og->approve($ca, $owner);
        $og->approve($cb, $owner);
        $owner = $this->as('owner');
        $mgr = $this->as('supervisor');
        $og->advance($ca->fresh(), ['kind' => 'cash', 'amount' => 18000, 'issued_on' => today()->subDays(40)->toDateString()], $mgr);
        $og->advance($cb->fresh(), ['kind' => 'cash', 'amount' => 35000, 'issued_on' => today()->subDays(35)->toDateString()], $mgr);
        $og->inspect($ca->fresh(), ['inspected_on' => today()->subDays(20)->toDateString(), 'stage' => 'fruit_set', 'estimated_quantity' => 390, 'outcome' => 'satisfactory',
            'notes' => 'Good set; some thrips scarring on the north rows.'], $this->as('supervisor'));
        $og->inspect($cb->fresh(), ['inspected_on' => today()->subDays(12)->toDateString(), 'stage' => 'maturity', 'estimated_quantity' => 720, 'outcome' => 'concern',
            'notes' => 'Irrigation erratic; fruit size below count 113 on block 3.'], $this->as('supervisor'));
        $sup = $this->as('supervisor');
        $og->collect($ca->fresh(), $cold, $lemons, ['collected_on' => today()->subDays(4)->toDateString(), 'quantity' => 42, 'rejected' => 2, 'grade' => 'Class 1', 'location' => 'Row D1'], $sup);
        $og->collect($ca->fresh(), $cold, $lemons, ['collected_on' => today()->subDays(2)->toDateString(), 'quantity' => 36, 'rejected' => 1, 'grade' => 'Class 2', 'location' => 'Row D2'], $sup);
        $og->collect($cb->fresh(), $cold, $lemons, ['collected_on' => today()->subDays(1)->toDateString(), 'quantity' => 55, 'rejected' => 5, 'grade' => 'Class 1', 'location' => 'Row D3'], $sup);
        $s = $og->draftSettlement($a->fresh(), $this->as('finance'));
        $og->approveSettlement($s, $this->as('owner'));
        $og->draftSettlement($b->fresh(), $this->as('finance'));

        return "3 growers, 2 contracts, 2 advances, 2 inspections, 3 collections, 2 settlements (1 approved)";
    }

    private function sheq(): string
    {
        $safety = app(FarmSafety::class);
        $owner = $this->u['owner'];
        $bSite = $safety->site($this->boplaas, $owner);
        $rSite = $safety->site($this->rivier, $owner);
        // The farm machines that need a statutory inspection, so the register can evidence them.
        $this->machine('T-01')->forceFill(['inspection_required' => true, 'inspection_expires_on' => '2027-02-28'])->saveQuietly();
        $this->machine('V-01')->forceFill(['inspection_required' => true, 'inspection_expires_on' => today()->addDays(18)->toDateString(), 'licence_expires_on' => '2027-03-31'])->saveQuietly();

        $cs = app(ComplianceSchemes::class);
        $gg = $cs->create($this->companyId, ['code' => 'globalgap-ifa-v6', 'name' => 'GLOBALG.A.P. IFA v6 (Fruit & Vegetables)', 'jurisdiction' => 'INT', 'scheme' => 'GLOBALG.A.P.'], $owner->id);
        foreach ([['FV 01.01', 'Site history and risk assessment for every production site', ['DM_DOCUMENT'], 'P12M', 'Farm Manager', 'Site management'],
            ['CB 07.01', 'Plant protection products are registered for the crop in South Africa', [\App\Modules\Agri\Services\FarmCompliance::CHEMICAL_REGISTRATION], 'P12M', 'Farm Manager', 'Plant protection'],
            ['CB 07.02', 'Operators applying plant protection products are trained and competent', [\App\Modules\Agri\Services\FarmCompliance::OPERATOR_CERTIFICATES], 'P12M', 'Farm Supervisor', 'Workers'],
            ['AF 03.01', 'Machinery is maintained and inspected as the law requires', [\App\Modules\Agri\Services\FarmCompliance::MACHINE_INSPECTION], 'P6M', 'Farm Manager', 'Equipment'],
            ['FV 05.02', 'Annual water quality test for irrigation and post-harvest water', ['DM_DOCUMENT'], 'P12M', 'Farm Manager', 'Water']] as [$clause, $req, $types, $freq, $role, $cat]) {
            $cs->clause($this->companyId, $gg, ['clause' => $clause, 'requirement' => $req, 'evidence_types' => $types, 'review_frequency' => $freq, 'responsible_role' => $role, 'category' => $cat]);
        }
        $cs->publish($this->companyId, $gg, $owner->id);
        $gg = DB::table('sheq_compliance_schemes')->find($gg->id);
        $c1 = $cs->adopt($this->companyId, $gg, $bSite, $owner->id);
        $c2 = $cs->adopt($this->companyId, $gg, $rSite, $owner->id);
        $siza = $cs->create($this->companyId, ['code' => 'siza-ethical-v5', 'name' => 'SIZA Social Standard v5', 'jurisdiction' => 'ZA', 'scheme' => 'SIZA'], $owner->id);
        foreach ([['2.1', 'Written employment contracts for every worker, in a language they understand', ['DM_DOCUMENT'], 'P12M', 'Farm Owner', 'Labour'],
            ['5.3', 'Working hours and overtime within the BCEA and sectoral determination 13', ['DM_DOCUMENT'], 'P3M', 'Farm Manager', 'Labour'],
            ['8.1', 'Health and safety representative appointed and trained', ['DM_DOCUMENT'], 'P12M', 'Farm Owner', 'Health and safety']] as [$clause, $req, $types, $freq, $role, $cat]) {
            $cs->clause($this->companyId, $siza, ['clause' => $clause, 'requirement' => $req, 'evidence_types' => $types, 'review_frequency' => $freq, 'responsible_role' => $role, 'category' => $cat]);
        }
        $cs->publish($this->companyId, $siza, $owner->id);
        $c3 = $cs->adopt($this->companyId, DB::table('sheq_compliance_schemes')->find($siza->id), $rSite, $owner->id);
        $lines = $c1['created'] + $c2['created'] + $c3['created'];

        return "2 SHEQ sites, 2 schemes published and adopted, {$lines} legal-register lines";
    }

    private function fleet(): string
    {
        $mm = app(MachineMaintenance::class);
        $ok = fn (FarmMachine $m, array $fail = []) => collect($mm->checklistFor($m))->map(fn ($i) => ['item' => $i['item'], 'ok' => ! array_key_exists($i['item'], $fail),
            'note' => $fail[$i['item']] ?? null])->all();
        $tractor = $this->machine('T-01');
        $sprayer = $this->machine('S-01');
        $harvester = $this->machine('H-01');
        $bakkie = $this->machine('V-01');
        $pump = $this->machine('PMP-01');
        $worker = $this->u['worker'];
        $sup = $this->u['supervisor'];
        $n = 0;
        foreach ([3, 2, 1] as $d) {
            Auth::setUser($worker);
            $mm->prestart($tractor->fresh(), $ok($tractor), ['checked_on' => today()->subDays($d)->toDateString(), 'hours_reading' => (float) $tractor->fresh()->hours_reading + 6], $worker);
            $n++;
        }
        $mm->prestart($sprayer->fresh(), $ok($sprayer, ['Nozzles and filters' => 'Two nozzles on section 3 blocked']), ['hours_reading' => (float) $sprayer->hours_reading + 4], $sup);
        $mm->prestart($pump->fresh(), $ok($pump), ['hours_reading' => (float) $pump->hours_reading + 22], $this->u['herdsman']);
        $n += 2;
        $fault = $mm->report($harvester, 'critical', 'Hydraulic hose on the header lift burst; oil on the feeder house. Do not run.', true, $sup);
        $mm->report($bakkie, 'minor', 'Left rear tyre slow puncture.', false, $this->u['herdsman']);
        $mm->claim($sprayer->fresh(), ['provider' => 'Jacto South Africa', 'amount' => 4850, 'reference' => 'JSA-WC-2026-118', 'claimed_on' => today()->toDateString()], $this->as('manager'));
        // Telematics: positions and engine hours, through the integration gateway.
        $int = app(Integrations::class);
        $pos = 0;
        foreach ([[$tractor, 'TRK-JD6120M', $this->field('L1')], [$sprayer, 'TRK-JACTO', $this->field('L1')], [$harvester, 'TRK-CASE7150', $this->field('L3')], [$bakkie, 'TRK-HILUX', $this->field('O1')]] as [$m, $ref, $f]) {
            $m->fresh()->forceFill(['telematics_ref' => $ref])->saveQuietly();
            $hours = (float) $m->fresh()->hours_reading;
            foreach ([3, 1] as $h) {
                $int->receive($this->companyId, 'telematics', "{$ref}-".now()->subHours($h)->format('YmdH'), ['ref' => $ref, 'recorded_at' => now()->subHours($h)->toDateTimeString(),
                    'latitude' => (float) $f->latitude + 0.0012 * $h, 'longitude' => (float) $f->longitude - 0.0009 * $h, 'engine_hours' => $hours + (3 - $h) * 1.5,
                    'odometer_km' => $m->machine_type === 'vehicle' ? 48210 + (3 - $h) * 35 : null], null, $m->farm_id);
                $pos++;
            }
        }

        return "{$n} pre-start checks (1 minor fault), 2 reported faults (H-01 stopped), 1 warranty claim, {$pos} telematics fixes";
    }

    private function officeStaff(): string
    {
        $owner = $this->u['owner'];
        $wf = app(Workforce::class);
        $ros = app(Rostering::class);
        $att = app(Attendance::class);
        $tpl = ShiftTemplate::query()->create(['company_id' => $this->companyId, 'code' => 'OFFICE', 'name' => 'Office hours', 'kind' => 'day',
            'starts' => '08:00', 'ends' => '16:30', 'break_minutes' => 30, 'min_staff' => 2]);
        $people = [];
        foreach (['finance', 'auditor'] as $k) {
            $person = HRPerson::withoutGlobalScopes()->where('user_id', $this->u[$k]->id)->first();
            if ($person && ! Worker::withoutGlobalScopes()->where('hr_person_id', $person->id)->exists()) {
                $people[] = $wf->enlist(null, $person, ['category' => 'permanent', 'hourly_cost_rate' => $k === 'finance' ? 185 : 140, 'contract_type' => 'permanent'], $owner);
            }
        }
        $new = $wf->registerMany(null, [['first_name' => 'Marietjie', 'surname' => 'Pretorius', 'phone_number' => '0583031144'],
            ['first_name' => 'Kabelo', 'surname' => 'Mofokeng', 'phone_number' => '0721186630'], ['first_name' => 'Zanele', 'surname' => 'Mabaso', 'phone_number' => '0834471902']],
            ['category' => 'permanent', 'hourly_cost_rate' => 96, 'contract_type' => 'permanent', 'contract_start' => '2025-02-01'], $owner);
        $people = array_merge($people, $new);
        $shifts = [];
        for ($d = -7; $d <= 4; $d++) {
            $date = today()->addDays($d);
            if ($date->isWeekend()) {
                continue;
            }
            $shift = $ros->fromTemplate(null, $tpl, $date, $owner);
            foreach ($people as $w) {
                $ros->assign($shift, $w, $owner, true);
            }
            $shifts[$d] = $shift;
        }
        $ros->publish($shifts, $owner);
        $events = 0;
        foreach ($shifts as $d => $shift) {
            if ($d >= 0) {
                continue;
            }
            foreach ($people as $i => $w) {
                if ($i === 2 && $d === -3) {
                    continue;   // off sick that day
                }
                $in = Carbon::parse($shift->starts_at)->addMinutes(($i * 4) % 9 - 3 + ($i === 1 && $d === -2 ? 27 : 0));
                $out = Carbon::parse($shift->ends_at)->addMinutes(($i * 5) % 12);
                $att->clock($w, 'in', $in, 'manual', ['device_ref' => 'HEAD-OFFICE'], $owner);
                $att->clock($w, 'out', $out, 'manual', ['device_ref' => 'HEAD-OFFICE'], $owner);
                $events += 2;
            }
        }
        if (now()->gt(today()->setTime(8, 15)) && ! today()->isWeekend()) {
            foreach (array_slice($people, 0, 3) as $i => $w) {
                $att->clock($w, 'in', today()->setTime(7, 55 + $i * 3), 'manual', ['device_ref' => 'HEAD-OFFICE'], $owner);
                $events++;
            }
        }
        $approved = 0;
        foreach (Timesheet::withoutGlobalScopes()->whereIn('worker_id', collect($people)->pluck('id'))->where('status', Timesheet::COMPLETE)->get() as $sheet) {
            if ($sheet->work_date->gte(today()->subDays(2))) {
                continue;
            }
            try {
                $att->approve($sheet, $this->u['manager']);
                $approved++;
            } catch (ValidationException) {
                // flagged: left for review
            }
        }

        return count($people).' office staff (no farm), '.count($shifts)." office shifts, {$events} clock events, {$approved} timesheets approved";
    }

    private function environment(): string
    {
        $env = app(EnvironmentReport::class);
        $mgr = $this->as('manager');
        $n = 0;
        foreach ([[$this->boplaas, 'L1', 'water', 'm3', 38400, 'meter', 'Pivot abstraction September (WUL 08/C83A)'],
            [$this->boplaas, null, 'energy', 'kWh', 18650, 'meter', 'Eskom — pivot pumps September'],
            [$this->boplaas, null, 'fuel', 'l', 4200, 'meter', 'Diesel issued — September'],
            [$this->boplaas, null, 'container_disposal', 'each', 64, 'count', 'Triple-rinsed containers to Agrirecovery collection point'],
            [$this->rivier, 'O1', 'water', 'm3', 9100, 'meter', 'Orchard micro-irrigation — September'],
            [$this->rivier, null, 'waste', 'kg', 1800, 'weighbridge', 'Packhouse culls to compost'],
            [$this->rivier, null, 'biodiversity', null, null, 'observation', 'Riparian strip along the Letaba fenced off (3.2 ha); 14 bird species counted']] as [$farm, $f, $kind, $unit, $qty, $method, $desc]) {
            $env->record($farm, ['field_id' => $f ? $this->field($f)->id : null, 'kind' => $kind, 'recorded_on' => today()->subDays(20 - $n)->toDateString(), 'quantity' => $qty, 'unit' => $unit,
                'method' => $method, 'description' => $desc], $mgr);
            $n++;
        }
        $env->record($this->boplaas, ['kind' => 'carbon_project', 'recorded_on' => today()->subDays(5)->toDateString(), 'quantity' => 212, 'unit' => 't CO2e', 'method' => 'model',
            'description' => 'No-till on Kliprant and Rivierkant — soil carbon baseline', 'verification' => 'verified', 'methodology' => 'VM0042 v2.0', 'verifier' => 'SGS South Africa'], $mgr,
            ['original_name' => 'soil-carbon-verification.pdf', 'mime' => 'application/pdf', 'data' => $this->pdf('soil carbon verification'), 'size' => 64]);
        $n++;
        $proc = app(FarmProcurement::class);
        $omnia = $this->party('Omnia Fertilizer (Bethlehem depot)', 'supplier', ['credit_terms_days' => 30]);
        $agri = $this->party('Agricol / Bayer Crop Science distributor', 'supplier', ['credit_terms_days' => 30]);
        $proc->dispute($this->boplaas, ['supplier_id' => $omnia->id, 'kind' => 'short_delivery', 'description' => 'LAN delivery note 34 t; weighbridge 32.6 t.', 'amount' => 13720], $mgr);
        $d = $proc->dispute($this->boplaas, ['supplier_id' => $agri->id, 'kind' => 'price', 'description' => 'Tebuconazole invoiced at R465/l against quote R420/l.', 'amount' => 1800], $mgr);
        $proc->settle($d, 'resolved', 'Supplier issued credit note CN-22871 for R1 800.', $this->as('owner'));

        return "{$n} environment records (1 verified), 2 supplier disputes (1 settled)";
    }

    private function insights(): string
    {
        $sr = app(ScheduledReports::class);
        $owner = $this->u['owner'];
        $farm = $this->boplaas->uuid;
        $sr->save(null, ['report_key' => 'agri:yield', 'title' => 'Monthly yield report — Boplaas', 'params' => ['farm' => $farm], 'period' => 'last_month', 'format' => 'xlsx',
            'frequency' => 'monthly', 'month_day' => 2, 'send_at' => '07:00', 'recipients' => ['owner@boplaas.demo', 'finance@boplaas.demo'], 'active' => true], $owner);
        $sr->save(null, ['report_key' => 'agri:labour', 'title' => 'Weekly labour cost', 'params' => [], 'period' => 'last_7_days', 'format' => 'xlsx',
            'frequency' => 'weekly', 'weekday' => 1, 'send_at' => '06:30', 'recipients' => ['owner@boplaas.demo', 'manager@boplaas.demo'], 'active' => true], $owner);
        $sr->save(null, ['report_key' => 'agri:chemicals', 'title' => 'Chemical applications (GLOBALG.A.P. record)', 'params' => [], 'period' => 'month_to_date', 'format' => 'csv',
            'frequency' => 'monthly', 'month_day' => 28, 'send_at' => '16:00', 'recipients' => ['manager@boplaas.demo'], 'active' => true], $owner);
        $sr->save(null, ['report_key' => 'agri:herd', 'title' => 'Herd register — Rivierplaas', 'params' => ['farm' => $this->rivier->uuid], 'period' => 'last_month', 'format' => 'xlsx',
            'frequency' => 'monthly', 'month_day' => 1, 'send_at' => '08:00', 'recipients' => ['herdsman@boplaas.demo'], 'active' => false], $owner);

        return '4 report schedules';
    }

    private function outcomes(): string
    {
        $o = app(FarmOutcomes::class);
        $owner = $this->u['owner'];
        foreach ([$this->boplaas, $this->rivier] as $farm) {
            $o->snapshot($farm, '2026-07-01', '2026-09-30', true, 'Baseline: the quarter before the packhouse and T&A went live', $owner);
            $o->snapshot($farm, '2026-10-01', today()->toDateString(), false, 'Month to date', $owner);
        }
        $p = app(AgriPredictions::class)->snapshot($this->companyId);
        $drafts = app(AgriDrafts::class)->propose([
            ['kind' => 'work_order', 'farm' => 'Boplaas', 'title' => 'Spray armyworm hotspots on Langkamp east edge', 'reason' => 'Open high-severity pest observation on MZ-26-L1 and the threshold is met.',
                'payload' => ['category' => 'spraying', 'title' => 'Spot-spray armyworm — Langkamp east']],
            ['kind' => 'requisition', 'farm' => 'Boplaas', 'title' => 'Re-order LAN before top-dressing', 'reason' => 'LAN runs out in 9 days at the planned rate; top-dressing starts 10 November.',
                'payload' => ['lines' => [['stock_item_id' => $this->existingItem('FERT-LAN')->id, 'quantity' => 12000]], 'justification' => 'V6 top-dress on 120 ha']],
            ['kind' => 'inspection', 'farm' => 'Boplaas', 'title' => 'Inspect the harvester before it returns to service', 'reason' => 'H-01 is stopped by a critical hydraulic fault.',
                'payload' => ['machine' => 'H-01']],
            ['kind' => 'report', 'farm' => 'Rivierplaas', 'title' => 'Cold room conditions this week', 'reason' => 'Two out-of-range readings on Koelkamer 1.', 'payload' => ['report' => 'storage']],
        ], $this->u['manager']);
        // A buyer price and a few local references the assistant answers from.
        foreach ([['Fall armyworm in maize — scouting and thresholds', 'agronomic', 'MAIZE-W', 'Scout 20 plants in 5 places per field twice a week from emergence. Act at 10% of plants with fresh window-pane damage up to V8.', 'ARC-GCI Fall armyworm guide 2024'],
            ['Citrus black spot — export requirements', 'regulation', 'CITRUS', 'Fruit for the EU must come from orchards on the CBS-free register or under an approved spray programme; keep the PHI records per block.', 'CRI / DALRRD export standards'],
            ['Wheat leaf rust — fungicide timing', 'agronomic', 'WHEAT', 'Protect the flag leaf: spray at first pustules from flag-leaf emergence; a second application 21 days later if wet.', 'ARC-SGI']] as [$title, $kind, $type, $content, $source]) {
            \App\Modules\Agri\Models\AgriReference::create(['company_id' => $this->companyId, 'title' => $title, 'kind' => $kind, 'crop_type_id' => $this->cropType($type), 'region' => 'ZA',
                'topics' => [$kind], 'content' => $content, 'source' => $source, 'status' => 'approved', 'approved_by' => $owner->id, 'approved_at' => now(), 'review_by' => today()->addYear(), 'created_by' => $this->u['manager']->id]);
        }

        return "4 outcome snapshots (2 baselines), {$p} predictions, ".count($drafts).' AI drafts, 3 references';
    }
}
