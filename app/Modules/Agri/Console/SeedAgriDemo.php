<?php

namespace App\Modules\Agri\Console;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\LivestockBreed;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockSpecies;
use App\Modules\Agri\Services\AgriCatalogue;
use App\Modules\Agri\Services\AgriLedger;
use App\Modules\Agri\Services\CropPlanService;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Services\FarmRegistry;
use App\Modules\Agri\Services\FarmStores;
use App\Modules\Agri\Services\FarmTasks;
use App\Modules\Agri\Services\FieldWork;
use App\Modules\Agri\Services\Harvests;
use App\Modules\Agri\Services\Livestock;
use App\Modules\Agri\Services\Machinery;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

/**
 * A working demonstration organisation for Mkhaya Agri: two farms (a crop farm and a mixed
 * livestock / citrus farm), people in each farm role, and a season of real-looking records —
 * plans, field work with inputs drawn from the farm stores, harvests, herds and flocks with
 * treatments and withdrawal periods, machines, tasks, compliance documents and money.
 *
 * Everything goes through the module's own services, so the demo obeys every rule the screens do
 * and posts to the ledger exactly as real use would. Idempotent: it refuses to run twice.
 */
class SeedAgriDemo extends Command
{
    protected $signature = 'agri:demo {--company=1 : Organisation to fill} {--password=Agri-Demo-2026! : Password for the demo users}';

    protected $description = 'Fill an organisation with Mkhaya Agri demonstration farms, people and a season of records';

    private int $companyId;

    private array $u = [];

    public function handle(TenantContext $tenants): int
    {
        $this->companyId = (int) $this->option('company');

        return $tenants->run($this->companyId, function () {
            if (Farm::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', 'BOPLAAS')->exists()) {
                $this->warn('The demo farms already exist; nothing was changed.');

                return self::SUCCESS;
            }
            $this->call('finance:ensure-chart', ['--company' => $this->companyId, '--install' => true]);
            app(AgriCatalogue::class)->ensureDefaults($this->companyId);
            $this->people();
            DB::transaction(fn () => $this->farms());
            app(FarmAlerts::class)->scan($this->companyId);
            $this->call('db:seed', ['--class' => \Database\Seeders\AgriModuleSeeder::class, '--force' => true]);
            // Every other area (packhouse, sales, irrigation, outgrowers, compliance, office staff…).
            $this->call('agri:demo-more', ['--company' => $this->companyId]);
            $this->info('Mkhaya Agri demo ready. Users (password '.$this->option('password').'):');
            foreach ($this->u as $key => $user) {
                $this->line("  {$key}: {$user->email}");
            }

            return self::SUCCESS;
        });
    }

    private function people(): void
    {
        foreach (RoleCatalog::everything() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $people = [
            'owner' => ['Pieter', 'van der Merwe', 'owner@boplaas.demo', 'Farm Owner'],
            'manager' => ['Thandi', 'Nkosi', 'manager@boplaas.demo', 'Farm Manager'],
            'supervisor' => ['Johannes', 'Mokoena', 'supervisor@boplaas.demo', 'Farm Supervisor'],
            'worker' => ['Sipho', 'Dlamini', 'worker@boplaas.demo', 'Farm Worker'],
            'finance' => ['Annelie', 'Botha', 'finance@boplaas.demo', 'Farm Finance Officer'],
            'viewer' => ['Lerato', 'Mahlangu', 'auditor@boplaas.demo', 'Farm Viewer'],
            'herdsman' => ['Bongani', 'Zulu', 'herdsman@boplaas.demo', 'Farm Worker'],
        ];
        $suggested = RoleCatalog::suggestedRoles();
        foreach ($people as $key => [$first, $last, $email, $roleName]) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($suggested[$roleName]);
            $user = User::withTrashed()->firstOrNew(['email' => $email]);
            $user->forceFill(['password' => Hash::make($this->option('password')), 'status' => 1, 'company_id' => $this->companyId, 'deleted_at' => null])->save();
            $user->syncRoles([$role]);
            DB::table('hr_people')->updateOrInsert(['user_id' => $user->id], ['company_id' => $this->companyId, 'first_name' => $first, 'surname' => $last,
                'email' => $email, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->u[$key] = $user->fresh();
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function farms(): void
    {
        $reg = app(FarmRegistry::class);
        $owner = $this->u['owner'];

        $boplaas = $reg->createFarm(['company_id' => $this->companyId, 'code' => 'BOPLAAS', 'name' => 'Boplaas', 'farm_type' => 'crop', 'location' => 'Bethlehem, Free State',
            'address' => 'R26, Bethlehem 9700', 'area' => 640, 'area_unit' => 'ha', 'latitude' => -28.2306, 'longitude' => 28.3071,
            'timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR'], $owner);
        $rivier = $reg->createFarm(['company_id' => $this->companyId, 'code' => 'RIVIER', 'name' => 'Rivierplaas', 'farm_type' => 'mixed', 'location' => 'Letsitele, Limpopo',
            'area' => 410, 'area_unit' => 'ha', 'latitude' => -23.8833, 'longitude' => 30.4000, 'timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR'], $owner);

        // People: the manager runs Boplaas; the supervisor and worker work on both; the herdsman on Rivierplaas.
        $grant = fn ($farm, $user, $role) => $reg->grant($farm, $user, $role, $owner);
        $grant($boplaas, $this->u['manager'], 'manager');
        $grant($boplaas, $this->u['supervisor'], 'supervisor');
        $grant($rivier, $this->u['supervisor'], 'supervisor');
        $grant($boplaas, $this->u['worker'], 'worker');
        $grant($rivier, $this->u['herdsman'], 'worker');
        $grant($boplaas, $this->u['finance'], 'finance');
        $grant($rivier, $this->u['finance'], 'finance');
        $grant($boplaas, $this->u['viewer'], 'viewer');
        app(\App\Modules\Agri\Services\FarmAccess::class)->flush();

        $f = [];
        foreach ([['L1', 'Langkamp', 'field', 120, 'Clay loam', 'centre_pivot'], ['L2', 'Rivierkant', 'field', 80, 'Sandy loam', 'dryland'],
            ['L3', 'Bo-land', 'field', 60, 'Clay loam', 'centre_pivot'], ['L4', 'Kliprant', 'field', 95, 'Sandy loam', 'dryland']] as [$code, $name, $type, $ha, $soil, $irr]) {
            $f[$code] = $reg->createField($boplaas, ['code' => $code, 'name' => $name, 'land_use_type' => $type, 'area' => $ha, 'area_unit' => 'ha', 'soil_type' => $soil, 'irrigation' => $irr], $owner);
        }
        foreach ([['O1', 'Suurlemoen boord', 'orchard', 45, 'micro'], ['K1', 'Kamp 1 (veld)', 'camp', 150, 'dryland'], ['K2', 'Kamp 2 (lusern)', 'paddock', 40, 'sprinkler'],
            ['H1', 'Leghuis 1', 'other', 0.2, null]] as [$code, $name, $type, $ha, $irr]) {
            $f[$code] = $reg->createField($rivier, ['code' => $code, 'name' => $name, 'land_use_type' => $type, 'area' => $ha, 'area_unit' => 'ha', 'irrigation' => $irr], $owner);
        }

        // ── Stores and stock ──────────────────────────────────────────────────────────────
        $inv = app(InventoryService::class);
        $item = fn ($code, $name, $uom, $cost, $reorder = 0) => FinStockItem::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->first()
            ?? $inv->createItem(['company_id' => $this->companyId, 'code' => $code, 'name' => $name, 'uom' => $uom, 'cost_method' => 'weighted_avg',
                'standard_cost' => $cost, 'is_active' => true, 'reorder_level' => $reorder]);
        $i = [
            'seed' => $item('SEED-MZ-7879', 'Maize seed DKC 78-79 (80k)', 'bag', 3450, 20),
            'soyseed' => $item('SEED-SOY-1521', 'Soybean seed PAN 1521R', 'bag', 1150, 10),
            'map' => $item('FERT-MAP', 'MAP fertiliser 11:22:0', 'kg', 14.20, 4000),
            'lan' => $item('FERT-LAN', 'LAN 28% N', 'kg', 9.80, 5000),
            'atrazine' => $item('HERB-ATR', 'Atrazine 500 SC', 'l', 95, 100),
            'teb' => $item('FUNG-TEB', 'Tebuconazole 250 EW', 'l', 420, 20),
            'diesel' => $item('DIESEL-50', 'Diesel 50ppm', 'l', 21.50, 2000),
            'lsd' => $item('VAC-LSD', 'Lumpy skin vaccine', 'dose', 18, 50),
            'oxy' => $item('AB-OXY-LA', 'Oxytetracycline LA (100 ml)', 'bottle', 165, 4),
            'mash' => $item('FEED-LAYER', 'Layer mash', 'kg', 7.50, 1500),
            'wheat' => $item('GRAIN-WHEAT', 'Wheat grain (B1)', 't', 0),
            'lemons' => $item('FRUIT-LEMON', 'Lemons (packhouse bins)', 'bin', 0),
        ];
        // What each item is to the farm, so stores, budgets and the environment report can tell seed
        // from fertiliser from fuel.
        foreach (['seed' => 'seed', 'soyseed' => 'seed', 'map' => 'fertiliser', 'lan' => 'fertiliser', 'atrazine' => 'crop_protection', 'teb' => 'crop_protection',
            'diesel' => 'fuel', 'lsd' => 'veterinary', 'oxy' => 'veterinary', 'mash' => 'feed', 'wheat' => 'produce', 'lemons' => 'produce'] as $k => $category) {
            \App\Modules\Agri\Models\AgriItemSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $this->companyId, 'stock_item_id' => $i[$k]->id], ['category' => $category]);
        }
        $stores = app(FarmStores::class);
        $open = '2026-08-01';
        foreach ([['seed', 140, 3450], ['soyseed', 70, 1150], ['map', 30000, 14.20], ['lan', 18000, 9.80], ['atrazine', 400, 95], ['teb', 80, 420], ['diesel', 6000, 21.50]] as [$k, $q, $c]) {
            $stores->receive($boplaas, $i[$k], $q, $c, $open, 'opening', null, $owner, "demo-open-B-{$k}");
        }
        foreach ([['lsd', 150, 18], ['oxy', 12, 165], ['mash', 6000, 7.50], ['diesel', 2500, 21.50]] as [$k, $q, $c]) {
            $stores->receive($rivier, $i[$k], $q, $c, $open, 'opening', null, $owner, "demo-open-R-{$k}");
        }

        // ── Machinery ─────────────────────────────────────────────────────────────────────
        $mach = app(Machinery::class);
        $tractor = $mach->register($boplaas, ['code' => 'T-01', 'name' => 'John Deere 6120M', 'machine_type' => 'tractor', 'make' => 'John Deere', 'model' => '6120M', 'year' => 2019,
            'registration' => 'FS 21-44 BTH', 'hours_reading' => 2210, 'service_interval_hours' => 250, 'hourly_rate' => 520, 'purchase_cost' => 1450000, 'purchased_on' => '2019-08-15']);
        $planter = $mach->register($boplaas, ['code' => 'P-01', 'name' => 'Kinze 3600 planter (12-row)', 'machine_type' => 'planter', 'make' => 'Kinze', 'year' => 2017, 'hours_reading' => 640, 'hourly_rate' => 300]);
        $sprayer = $mach->register($boplaas, ['code' => 'S-01', 'name' => 'Jacto Uniport 2500 sprayer', 'machine_type' => 'sprayer', 'make' => 'Jacto', 'year' => 2021, 'hours_reading' => 905,
            'service_interval_hours' => 200, 'hourly_rate' => 610]);
        $harvester = $mach->register($boplaas, ['code' => 'H-01', 'name' => 'Case IH Axial-Flow 7150', 'machine_type' => 'harvester', 'make' => 'Case IH', 'year' => 2016, 'hours_reading' => 3120,
            'service_interval_hours' => 300, 'hourly_rate' => 1450]);
        $bakkie = $mach->register($rivier, ['code' => 'V-01', 'name' => 'Toyota Hilux 2.4 GD-6', 'machine_type' => 'vehicle', 'make' => 'Toyota', 'year' => 2022, 'registration' => 'LP 77 KK GP', 'hours_reading' => 0]);
        $pump = $mach->register($rivier, ['code' => 'PMP-01', 'name' => 'Borehole pump (orchard)', 'machine_type' => 'pump', 'hours_reading' => 11800, 'service_interval_hours' => 2000]);
        $pump->update(['next_service_hours' => 12000, 'next_service_on' => '2026-10-28']);

        // ── Crops ─────────────────────────────────────────────────────────────────────────
        $plans = app(CropPlanService::class);
        $work = app(FieldWork::class);
        $harv = app(Harvests::class);
        $summer = CropSeason::query()->whereDate('starts_on', '<=', '2026-10-19')->whereDate('ends_on', '>=', '2026-10-19')->where('code', 'like', 'S%')->first();
        $winter = CropSeason::query()->where('code', 'like', 'W%')->first();
        $type = fn ($code) => CropType::query()->where('code', $code)->value('id');
        $variety = fn ($name) => CropVariety::query()->where('name', $name)->value('id');
        $mk = function (Farm $farm, FarmField $field, array $d) use ($plans) {
            return $plans->create($farm, $field, $d + ['area_unit' => 'ha', 'yield_unit' => 't'], $this->u['manager']);
        };

        $maize = $mk($boplaas, $f['L1'], ['code' => 'MZ-26-L1', 'season_id' => $summer->id, 'crop_type_id' => $type('MAIZE-W'), 'variety_id' => $variety('DKC 78-79'),
            'planned_area' => 120, 'planned_planting_on' => '2026-10-01', 'planned_harvest_on' => '2027-05-15', 'expected_yield' => 9.5, 'expected_price' => 4100, 'budget_amount' => 1150000,
            'notes' => 'Pivot under irrigation. Target 9.5 t/ha.']);
        $plans->approve($maize, $this->u['owner']);
        $plans->plant($maize, '2026-10-06');

        $soy = $mk($boplaas, $f['L2'], ['code' => 'SOY-26-L2', 'season_id' => $summer->id, 'crop_type_id' => $type('SOY'), 'variety_id' => $variety('PAN 1521R'),
            'planned_area' => 80, 'planned_planting_on' => '2026-11-15', 'planned_harvest_on' => '2027-04-20', 'expected_yield' => 3.2, 'expected_price' => 7800, 'budget_amount' => 520000]);
        $plans->approve($soy, $this->u['owner']);

        $sunflower = $mk($boplaas, $f['L4'], ['code' => 'SUN-26-L4', 'season_id' => $summer->id, 'crop_type_id' => $type('SUNFLOWER'),
            'planned_area' => 95, 'planned_planting_on' => '2026-12-01', 'planned_harvest_on' => '2027-05-30', 'expected_yield' => 2.1, 'budget_amount' => 380000]);

        $wheat = $mk($boplaas, $f['L3'], ['code' => 'WH-26-L3', 'season_id' => $winter->id, 'crop_type_id' => $type('WHEAT'), 'variety_id' => $variety('SST 8154'),
            'planned_area' => 60, 'planned_planting_on' => '2026-05-10', 'planned_harvest_on' => '2026-10-15', 'expected_yield' => 6.5, 'expected_price' => 5200, 'budget_amount' => 410000]);
        $plans->approve($wheat, $this->u['owner']);
        $plans->plant($wheat, '2026-05-12');

        $lemons = $mk($rivier, $f['O1'], ['code' => 'CIT-26-O1', 'season_id' => $winter->id, 'crop_type_id' => $type('CITRUS'),
            'planned_area' => 45, 'planned_planting_on' => '2026-03-01', 'planned_harvest_on' => '2026-11-30', 'expected_yield' => 55, 'yield_unit' => 'bins', 'budget_amount' => 690000]);
        $plans->approve($lemons, $this->u['owner']);
        $plans->plant($lemons, '2026-03-01');

        $act = function (Farm $farm, CropPlan $plan, string $type, string $title, string $date, array $inputs = [], ?array $done = null, ?FarmMachine $m = null, $assignee = null) use ($work) {
            $a = $work->create($farm, null, $plan, null, ['activity_type' => $type, 'title' => $title, 'planned_on' => $date, 'machine_id' => $m?->id,
                'assigned_to' => $assignee?->id], $this->u['manager']);
            foreach ($inputs as [$item, $qty, $rate, $whp]) {
                $work->addInput($a, $item, ['quantity' => $qty, 'rate_per_ha' => $rate, 'withholding_days' => $whp]);
            }
            if ($done !== null) {
                $work->complete($a->fresh(), $done + ['completed_at' => $date.' 16:00:00'], $this->u['supervisor']);
            }

            return $a;
        };
        // Wheat: the winter crop, being harvested now.
        $act($boplaas, $wheat, 'planting', 'Plant wheat SST 8154', '2026-05-12', [[$i['map'], 9000, 150, null]], ['area_covered' => 60, 'labour_hours' => 22, 'labour_cost' => 2600, 'machine_hours' => 14], $tractor);
        $act($boplaas, $wheat, 'fertilising', 'Top dress LAN', '2026-07-02', [[$i['lan'], 9000, 150, null]], ['area_covered' => 60, 'labour_hours' => 8, 'labour_cost' => 950, 'machine_hours' => 6], $tractor);
        $act($boplaas, $wheat, 'spraying', 'Fungicide — leaf rust', '2026-09-01', [[$i['teb'], 30, 0.5, 28]], ['area_covered' => 60, 'labour_hours' => 5, 'labour_cost' => 600, 'machine_hours' => 4, 'weather' => 'Calm, 18°C'], $sprayer);
        $act($boplaas, $wheat, 'harvesting', 'Combine wheat — first half', '2026-10-12', [[$i['diesel'], 900, null, null]], ['area_covered' => 32, 'labour_hours' => 18, 'labour_cost' => 2100, 'machine_hours' => 16], $harvester);
        $harv->record($wheat, ['harvested_on' => '2026-10-12', 'quantity' => 196, 'unit' => 't', 'grade' => 'B1', 'moisture_pct' => 12.4, 'area_harvested' => 32,
            'destination' => 'store', 'stock_item_id' => $i['wheat']->id, 'unit_value' => 5150], $this->u['supervisor']);
        $harv->record($wheat, ['harvested_on' => '2026-10-14', 'quantity' => 120, 'unit' => 't', 'grade' => 'B2', 'moisture_pct' => 13.1, 'area_harvested' => 19,
            'destination' => 'sold', 'unit_value' => 4950, 'settled_by' => 'receivable', 'notes' => 'Sold off the field to VKB Bethlehem'], $this->u['supervisor']);

        // Maize: planted this month.
        $act($boplaas, $maize, 'soil_preparation', 'Disc and rip Langkamp', '2026-09-20', [[$i['diesel'], 1400, null, null]], ['area_covered' => 120, 'labour_hours' => 40, 'labour_cost' => 4800, 'machine_hours' => 32], $tractor);
        $act($boplaas, $maize, 'planting', 'Plant DKC 78-79 + MAP', '2026-10-06', [[$i['seed'], 120, 1, null], [$i['map'], 18000, 150, null]],
            ['area_covered' => 120, 'labour_hours' => 30, 'labour_cost' => 3600, 'machine_hours' => 24], $planter);
        $act($boplaas, $maize, 'spraying', 'Pre-emergence atrazine', '2026-10-08', [[$i['atrazine'], 240, 2, 60]], ['area_covered' => 120, 'labour_hours' => 9, 'labour_cost' => 1100, 'machine_hours' => 8], $sprayer);
        $act($boplaas, $maize, 'scouting', 'Scout for armyworm — Langkamp', '2026-10-21', [], null, null, $this->u['worker']);
        $act($boplaas, $maize, 'fertilising', 'Top dress LAN (V6)', '2026-11-10', [[$i['lan'], 12000, 100, null]], null, $tractor, $this->u['worker']);
        // Soy: approved, first job planned.
        $act($boplaas, $soy, 'soil_preparation', 'Burn-down and disc Rivierkant', '2026-11-05', [], null, $tractor, $this->u['worker']);
        // Lemons.
        $act($rivier, $lemons, 'irrigation', 'Fertigation cycle 7', '2026-09-15', [], ['area_covered' => 45, 'labour_hours' => 6, 'labour_cost' => 700]);
        $act($rivier, $lemons, 'harvesting', 'Pick block A', '2026-10-09', [], ['area_covered' => 20, 'labour_hours' => 160, 'labour_cost' => 18400]);
        $harv->record($lemons, ['harvested_on' => '2026-10-09', 'quantity' => 950, 'unit' => 'bins', 'grade' => 'Class 1', 'destination' => 'store',
            'stock_item_id' => $i['lemons']->id, 'unit_value' => 1350], $this->u['supervisor']);

        \App\Modules\Agri\Models\CropObservation::create(['company_id' => $this->companyId, 'farm_id' => $boplaas->id, 'field_id' => $f['L1']->id, 'crop_plan_id' => $maize->id,
            'observed_on' => '2026-10-17', 'kind' => 'pest', 'severity' => 'high', 'growth_stage' => 'VE–V2', 'action_required' => true, 'status' => 'open',
            'description' => 'Fall armyworm larvae and window-pane feeding on ±8% of seedlings along the eastern pivot edge.', 'recorded_by' => $this->u['worker']->id]);
        \App\Modules\Agri\Models\CropObservation::create(['company_id' => $this->companyId, 'farm_id' => $boplaas->id, 'field_id' => $f['L3']->id, 'crop_plan_id' => $wheat->id,
            'observed_on' => '2026-08-28', 'kind' => 'disease', 'severity' => 'medium', 'growth_stage' => 'Flag leaf', 'action_required' => true, 'status' => 'actioned',
            'description' => 'Leaf rust pustules on lower leaves — fungicide scheduled.', 'recorded_by' => $this->u['supervisor']->id]);

        // Machine use outside activities, with fuel bought for the bakkie.
        $mach->logUse($bakkie, ['used_on' => '2026-10-16', 'hours' => 0.1, 'fuel_litres' => 62, 'fuel_cost' => 1395, 'notes' => 'Trip to co-op', 'settled_by' => 'bank'], $this->u['herdsman']);
        $mach->service($harvester, ['serviced_on' => '2026-09-25', 'service_type' => 'service', 'description' => 'Pre-season service, new rasp bars', 'provider' => 'Case IH Bethlehem', 'cost' => 38500], $this->u['manager']);
        $tractor->update(['next_service_on' => '2026-10-27']);

        // ── Livestock ─────────────────────────────────────────────────────────────────────
        $ls = app(Livestock::class);
        $sp = fn ($code) => LivestockSpecies::query()->where('code', $code)->value('id');
        $breed = fn ($name) => LivestockBreed::query()->where('name', $name)->value('id');
        $hm = $this->u['herdsman'];
        $herd = $ls->createGroup($rivier, ['species_id' => $sp('CATTLE'), 'code' => 'BONS-BR', 'name' => 'Bonsmara breeding herd', 'purpose' => 'breeding', 'field_id' => $f['K1']->id,
            'started_on' => '2024-02-01', 'budget_amount' => 260000], $this->u['manager']);
        $weaners = $ls->createGroup($rivier, ['species_id' => $sp('CATTLE'), 'code' => 'WEAN-26', 'name' => 'Weaners 2026', 'purpose' => 'weaners', 'field_id' => $f['K2']->id], $this->u['manager']);
        $bull = $ls->register($rivier, ['species_id' => $sp('CATTLE'), 'breed_id' => $breed('Bonsmara'), 'group_id' => $herd->id, 'field_id' => $f['K1']->id, 'tag_number' => 'RV-B001',
            'name' => 'Koning', 'sex' => 'male', 'birth_date' => '2021-09-03', 'acquisition' => 'purchased', 'acquired_on' => '2023-06-10', 'acquisition_cost' => 68000], $hm);
        $cows = [];
        foreach (range(1, 14) as $n) {
            $cows[] = $ls->register($rivier, ['species_id' => $sp('CATTLE'), 'breed_id' => $breed($n % 4 === 0 ? 'Nguni' : 'Bonsmara'), 'group_id' => $herd->id, 'field_id' => $f['K1']->id,
                'tag_number' => sprintf('RV-C%03d', $n), 'sex' => 'female', 'birth_date' => sprintf('20%02d-%02d-12', 17 + $n % 5, 1 + $n % 11), 'acquisition' => 'born'], $hm);
        }
        foreach (range(1, 8) as $n) {
            $dam = $cows[$n];
            $ls->register($rivier, ['species_id' => $sp('CATTLE'), 'breed_id' => $breed('Bonsmara'), 'group_id' => $weaners->id, 'field_id' => $f['K2']->id,
                'tag_number' => sprintf('RV-26%02d', $n), 'sex' => $n % 2 ? 'male' : 'female', 'birth_date' => sprintf('2026-%02d-%02d', 1 + $n % 3, 4 + $n),
                'acquisition' => 'born', 'dam_id' => $dam->id, 'sire_id' => $bull->id], $hm);
        }
        foreach ($cows as $k => $cow) {
            foreach ([['2026-05-20', 455], ['2026-08-20', 471], ['2026-10-15', 486]] as [$d, $w]) {
                $ls->weigh($rivier, $cow, null, ['weighed_on' => $d, 'weight_kg' => $w + ($k * 7) % 40, 'condition_score' => 5 + ($k % 3) * 0.5], $hm);
            }
        }
        $ls->treat($rivier, null, $herd, ['event_type' => 'vaccination', 'treated_on' => '2026-09-05', 'condition' => 'Lumpy skin disease (annual)', 'stock_item_id' => $i['lsd']->id,
            'quantity' => 15, 'head_count' => 15, 'dose' => '2 ml SC', 'next_due_on' => '2027-09-05', 'vet_name' => 'Dr. M. Ramathuba'], $hm);
        $ls->treat($rivier, $cows[3], null, ['event_type' => 'treatment', 'treated_on' => '2026-10-13', 'condition' => 'Foot rot', 'stock_item_id' => $i['oxy']->id,
            'quantity' => 1, 'dose' => '20 ml IM', 'withdrawal_days' => 28, 'vet_name' => 'Dr. M. Ramathuba'], $hm);
        $ls->treat($rivier, null, $weaners, ['event_type' => 'deworming', 'treated_on' => '2026-10-01', 'product_name' => 'Ivermectin 1%', 'dose' => '1 ml/50 kg', 'head_count' => 8,
            'withdrawal_days' => 35, 'next_due_on' => '2026-10-24', 'cost' => 640], $hm);
        $ls->event($cows[5], 'pregnancy_check', '2026-08-02', $hm, 'Pregnant — ±5 months');
        $ls->event($cows[6], 'pregnancy_check', '2026-08-02', $hm, 'Open');
        $ls->exit($cows[13], 'sold', '2026-09-18', 'Cull cow sold at Letsitele auction', 14800, $this->u['manager'], 'bank');
        $ls->exit($cows[12], 'died', '2026-10-03', 'Redwater (heartwater suspected)', null, $hm);

        $sheep = $ls->createGroup($rivier, ['species_id' => $sp('SHEEP'), 'code' => 'DORP-1', 'name' => 'Dorper ewes', 'purpose' => 'mutton', 'field_id' => $f['K1']->id], $this->u['manager']);
        foreach (range(1, 6) as $n) {
            $ls->register($rivier, ['species_id' => $sp('SHEEP'), 'breed_id' => $breed('Dorper'), 'group_id' => $sheep->id, 'tag_number' => sprintf('DP-%03d', $n),
                'sex' => $n === 1 ? 'male' : 'female', 'birth_date' => '2024-0'.(1 + $n % 8).'-15', 'acquisition' => 'purchased', 'acquired_on' => '2025-02-01', 'acquisition_cost' => 2600], $hm);
        }
        $layers = $ls->createGroup($rivier, ['species_id' => $sp('CHICKEN-L'), 'code' => 'LAY-H1', 'name' => 'Layers — house 1', 'purpose' => 'layers', 'field_id' => $f['H1']->id,
            'head_count' => 2400, 'started_on' => '2026-03-01', 'budget_amount' => 210000], $this->u['manager']);
        $ls->changeCount($layers, -18, 'death', '2026-09-30', $hm, 'Heat stress, end of September');
        $ls->changeCount($layers, -100, 'sale', '2026-10-10', $this->u['manager'], 'Spent hens to local buyer', 4500, 'bank');
        $feeding = $work->create($rivier, $f['H1'], null, $layers, ['activity_type' => 'feeding', 'title' => 'Layer mash — week 41', 'planned_on' => '2026-10-12'], $hm);
        $work->addInput($feeding, $i['mash'], ['quantity' => 1750]);
        $work->complete($feeding->fresh(), ['completed_at' => '2026-10-12 17:00:00', 'labour_hours' => 10, 'labour_cost' => 900], $hm);

        // ── Tasks ─────────────────────────────────────────────────────────────────────────
        $t = app(FarmTasks::class);
        $task = fn ($farm, $d) => $t->create($farm, $d + ['priority' => 'normal', 'requires_approval' => true], $this->u['manager']);
        $task($boplaas, ['title' => 'Spray armyworm hotspots on Langkamp east edge', 'description' => 'Use the boom sprayer; check product with the supervisor first.',
            'priority' => 'urgent', 'due_on' => '2026-10-20', 'assigned_to' => $this->u['supervisor']->id, 'field_id' => $f['L1']->id]);
        $task($boplaas, ['title' => 'Fix pivot tower 4 wheel track', 'priority' => 'high', 'due_on' => '2026-10-16', 'assigned_to' => $this->u['worker']->id, 'field_id' => $f['L1']->id]);
        $sub = $task($boplaas, ['title' => 'Clean and calibrate planter units', 'due_on' => '2026-10-18', 'assigned_to' => $this->u['worker']->id]);
        $t->submit($sub, ['labour_hours' => 5, 'completion_notes' => 'All 12 units cleaned, finger pickups checked, calibrated to 80k seeds/ha.'], $this->u['worker']);
        $done = $task($boplaas, ['title' => 'Grain bin fumigation check', 'due_on' => '2026-10-13', 'assigned_to' => $this->u['worker']->id]);
        $t->submit($done, ['labour_hours' => 2, 'completion_notes' => 'Bins 1–3 sealed; tags on doors.'], $this->u['worker']);
        $t->review($done, true, 'Good.', $this->u['supervisor']);
        $task($rivier, ['title' => 'Move weaners to Kamp 2 lucerne', 'due_on' => '2026-10-22', 'assigned_to' => $this->u['herdsman']->id, 'field_id' => $f['K2']->id]);
        $task($rivier, ['title' => 'Check water troughs in Kamp 1', 'due_on' => '2026-10-19', 'assigned_to' => $this->u['herdsman']->id, 'requires_approval' => false]);
        $task($boplaas, ['title' => 'Book soil samples for Kliprant', 'priority' => 'low', 'due_on' => '2026-11-05']);

        // ── Documents ─────────────────────────────────────────────────────────────────────
        $pdf = base64_encode("%PDF-1.4\n% Mkhaya Agri demo document\n");
        // Farm records are filed in Document Management (as the manager, who files them; someone else approves).
        \Illuminate\Support\Facades\Auth::setUser($this->u['manager']);
        $doc = fn ($farm, $d) => app(\App\Modules\Agri\Services\FarmDocuments::class)->file($farm, $d,
            ['original_name' => \Illuminate\Support\Str::slug($d['title']).'.pdf', 'mime' => 'application/pdf', 'data' => $pdf], $this->u['manager']);
        $doc($boplaas, ['document_type' => 'title_deed', 'title' => 'Title deed — Boplaas 1123', 'reference' => 'T12345/1998', 'issued_on' => '1998-04-01']);
        $doc($boplaas, ['document_type' => 'water_use_licence', 'title' => 'Water use licence — pivots L1 & L3', 'reference' => 'WUL 08/C83A/A/7712', 'issued_on' => '2021-11-05', 'expires_on' => '2026-11-05']);
        $doc($boplaas, ['document_type' => 'operator_certificate', 'title' => 'Pest control operator — J. Mokoena', 'reference' => 'PCO-FS-20931', 'issued_on' => '2023-10-01', 'expires_on' => '2026-10-01']);
        $doc($boplaas, ['document_type' => 'certification', 'title' => 'GLOBALG.A.P. IFA v6 certificate', 'reference' => 'GGN 4063061234567', 'issued_on' => '2026-03-12', 'expires_on' => '2027-03-11']);
        $doc($rivier, ['document_type' => 'certification', 'title' => 'Livestock brand registration — RVP', 'reference' => 'ANIM-ID 55120', 'issued_on' => '2019-05-02']);
        $doc($rivier, ['document_type' => 'permit', 'title' => 'Red-line movement permit (cattle)', 'reference' => 'VS-LP-2026-0441', 'issued_on' => '2026-09-10', 'expires_on' => '2026-11-10']);

        // ── Money ─────────────────────────────────────────────────────────────────────────
        $ledger = app(AgriLedger::class);
        $txn = function (Farm $farm, string $kind, string $cat, string $date, string $desc, float $amt, string $settled, $enterprise = null, ?string $party = null) use ($ledger) {
            $t = \App\Modules\Agri\Models\FarmTransaction::create(['company_id' => $this->companyId, 'farm_id' => $farm->id, 'kind' => $kind, 'category' => $cat,
                'enterprise' => $enterprise ? AgriLedger::enterpriseKey($enterprise) : null, 'transacted_on' => $date, 'description' => $desc, 'amount' => $amt,
                'settled_by' => $settled, 'counterparty' => $party, 'recorded_by' => $this->u['finance']->id]);
            $dims = AgriLedger::dimensions($farm, null, $enterprise, $cat);
            $j = $kind === 'expense'
                ? $ledger->expense($farm, $amt, $date, $desc, $dims, $settled, config('agri.accounts.direct_expense'), "demo-{$t->uuid}")
                : $ledger->sale($farm, $amt, $date, $desc, $dims, $cat === 'livestock' ? 'livestock' : 'produce', $settled, "demo-{$t->uuid}");
            $t->update(['journal_id' => $j->id]);
        };
        $txn($boplaas, 'expense', 'contractors', '2026-09-02', 'Aerial top-up spray — Bo-land wheat', 18500, 'payable', $wheat, 'Free State Aviation');
        $txn($boplaas, 'expense', 'utilities', '2026-09-30', 'Eskom — pivot pumps September', 12400, 'bank', null, 'Eskom');
        $txn($boplaas, 'expense', 'labour', '2026-09-30', 'Seasonal labour — wheat harvest crew', 21600, 'bank', $wheat);
        $txn($rivier, 'sale', 'produce', '2026-10-15', 'Lemons — 400 bins to Letaba Packhouse', 540000, 'receivable', $lemons, 'Letaba Packhouse');
        $txn($rivier, 'expense', 'feed', '2026-10-01', 'Lucerne bales — Kamp 2', 9600, 'payable', $weaners, 'Tzaneen Voer');
        foreach ([[$boplaas, null, 'overheads', 'Insurance, rates, admin', 180000], [$boplaas, $wheat, 'inputs', 'Wheat inputs', 330000], [$boplaas, $maize, 'inputs', 'Maize inputs', 820000],
            [$rivier, $herd, 'animal_health', 'Herd health programme', 22000], [$rivier, null, 'labour', 'Permanent staff', 420000]] as [$farm, $ent, $cat, $desc, $amt]) {
            \App\Modules\Agri\Models\FarmBudgetLine::create(['company_id' => $this->companyId, 'farm_id' => $farm->id, 'enterprise' => $ent ? AgriLedger::enterpriseKey($ent) : null,
                'category' => $cat, 'description' => $desc, 'amount' => $amt, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'created_by' => $this->u['finance']->id]);
        }
    }
}
