<?php

namespace Tests\Feature\Agri;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared setup for the Mkhaya Agri suite: an entitled organisation with the module active, and the
 * people the specification talks about — an organisation administrator who reaches every farm, a
 * farm manager confined to the farms they are given, a field worker, and somebody with no farm
 * permissions at all.
 */
abstract class AgriTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected User $manager;

    protected User $worker;

    protected User $outsider;

    protected User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->activateModule();
        foreach (RoleCatalog::everything() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->company = Company::create(['name' => 'Boplaas Boerdery']);
        $this->entitle($this->company);

        $adminRole = Role::firstOrCreate(['name' => 'agri test org admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(RoleCatalog::all());

        $roles = RoleCatalog::suggestedRoles();
        $managerRole = Role::firstOrCreate(['name' => 'agri test farm manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions($roles['Farm Manager']);

        $workerRole = Role::firstOrCreate(['name' => 'agri test worker', 'guard_name' => 'web']);
        $workerRole->syncPermissions($roles['Farm Worker']);

        $supervisorRole = Role::firstOrCreate(['name' => 'agri test supervisor', 'guard_name' => 'web']);
        $supervisorRole->syncPermissions($roles['Farm Supervisor']);

        $this->admin = $this->makeUser('admin@boplaas.test', $adminRole, $this->company);
        $this->manager = $this->makeUser('manager@boplaas.test', $managerRole, $this->company);
        $this->worker = $this->makeUser('worker@boplaas.test', $workerRole, $this->company);
        $this->outsider = $this->makeUser('outsider@boplaas.test', null, $this->company);
        $this->supervisor = $this->makeUser('supervisor@boplaas.test', $supervisorRole, $this->company);
    }

    /**
     * Books the stock ledger and farm postings can use: the shared chart, the company's control
     * accounts (installed the way a real tenant gets them) and an open period.
     */
    protected function openBooks(): void
    {
        if (! \App\Modules\Finance\Accounting\Models\FinAccount::withoutGlobalScopes()->whereNull('company_id')->where('code', '1310')->exists()) {
            (new \Database\Seeders\FinanceGlSeeder())->run();
        }
        $this->artisan('finance:ensure-chart', ['--company' => $this->company->id, '--install' => true]);

        foreach ([now()->subMonthNoOverflow(), now()] as $d) {
            if (! \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::forDate($d, null)) {
                \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::create([
                    'company_id' => null, 'fiscal_year' => (int) $d->year, 'period_no' => (int) $d->month,
                    'start_date' => $d->copy()->startOfMonth(), 'end_date' => $d->copy()->endOfMonth(), 'status' => 'open',
                ]);
            }
        }
    }

    protected function stockItem(string $code, string $uom = 'kg', float $cost = 10, float $reorder = 0): \App\Modules\Finance\Accounting\Models\FinStockItem
    {
        return $this->asCompany($this->company, fn () => app(\App\Modules\Finance\Accounting\Services\InventoryService::class)->createItem([
            'company_id' => $this->company->id, 'code' => $code, 'name' => "{$code} (test)", 'uom' => $uom,
            'cost_method' => 'weighted_avg', 'standard_cost' => $cost, 'is_active' => true, 'reorder_level' => $reorder,
        ]));
    }

    /** Stock on the farm's shelf, received the way the farm screen receives it. */
    protected function stockOnFarm(Farm $farm, \App\Modules\Finance\Accounting\Models\FinStockItem $item, float $qty, float $cost): void
    {
        $this->actingAs($this->admin)->post(route('agri.stock.receive'), [
            'farm_id' => $farm->uuid, 'stock_item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $cost,
            'received_on' => today()->toDateString(), 'basis' => 'opening',
        ])->assertSessionHasNoErrors();
    }

    protected function catalogue(): void
    {
        $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\AgriCatalogue::class)->ensureDefaults($this->company->id));
    }

    protected function field(Farm $farm, string $code = 'F1', float $ha = 50): \App\Modules\Agri\Models\FarmField
    {
        return $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\FarmField::withoutGlobalScopes()->create([
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'code' => $code, 'name' => "Land {$code}",
            'land_use_type' => 'field', 'area' => $ha, 'area_unit' => 'ha', 'status' => 'active',
        ]));
    }

    protected function seasonId(): int
    {
        return (int) \App\Modules\Agri\Models\CropSeason::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->orderBy('starts_on')->value('id');
    }

    protected function cropTypeId(string $code = 'MAIZE-W'): int
    {
        return (int) \App\Modules\Agri\Models\CropType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    /** A farm worker: an HR person (with the given login, or none) put to work on the farm. */
    protected function worker(Farm $farm, string $first, ?User $login = null, array $o = []): \App\Modules\HR\Hr\Attendance\Models\Worker
    {
        return $this->asCompany($this->company, function () use ($farm, $first, $login, $o) {
            $p = new \App\Modules\HR\Hr\Models\HRPerson(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => $first, 'surname' => 'Test',
                'user_id' => $login?->id, 'status' => 1, 'employee_number' => 'T'.\Illuminate\Support\Str::random(6)]);
            $p->company_id = $farm->company_id;
            $p->save();

            return \App\Modules\HR\Hr\Attendance\Models\Worker::withoutGlobalScopes()->create($o + ['company_id' => $farm->company_id, 'farm_id' => $farm->id,
                'hr_person_id' => $p->id, 'category' => 'seasonal', 'badge_token' => \Illuminate\Support\Str::random(40), 'status' => 'active',
                'hourly_cost_rate' => 30]);
        });
    }

    protected function template(string $code, string $starts, string $ends, int $break = 30, array $o = []): \App\Modules\HR\Hr\Attendance\Models\ShiftTemplate
    {
        return $this->asCompany($this->company, fn () => \App\Modules\HR\Hr\Attendance\Models\ShiftTemplate::withoutGlobalScopes()->create($o + [
            'company_id' => $this->company->id, 'code' => $code, 'name' => "{$code} shift", 'kind' => 'day', 'starts' => $starts, 'ends' => $ends,
            'break_minutes' => $break, 'min_staff' => 1]));
    }

    protected function rules(array $o = []): \App\Modules\HR\Hr\Attendance\Models\RuleSet
    {
        return $this->asCompany($this->company, function () use ($o) {
            $r = app(\App\Modules\HR\Hr\Attendance\Services\Workforce::class)->defaultRules($this->company->id);
            $r->update($o);

            return $r->fresh();
        });
    }

    /** Give an HR person a SHEQ competency (the code is created in the catalogue if needed). */
    protected function competency(\App\Modules\HR\Hr\Attendance\Models\Worker $w, string $code, ?string $expires = null): void
    {
        $this->asCompany($this->company, function () use ($w, $code, $expires) {
            if (! \Illuminate\Support\Facades\DB::table('sheq_competencies')->where('company_id', $this->company->id)->where('code', $code)->exists()) {
                \Illuminate\Support\Facades\DB::table('sheq_competencies')->insert(['company_id' => $this->company->id, 'code' => $code, 'title' => "{$code} certificate",
                    'competency_type' => 'LICENCE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(\App\Modules\Operations\Sheq\Services\CompetencyService::class)->recordManual($this->company->id, $w->hr_person_id, $code,
                ['achieved_at' => today()->subYear()->toDateString(), 'expires_at' => $expires ?? today()->addYear()->toDateString()]);
        });
    }

    protected function speciesId(string $code): int
    {
        return (int) \App\Modules\Agri\Models\LivestockSpecies::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    protected function activateModule(): void
    {
        $categoryId = DB::table('security_categories')->where('name', 'Farm Operations')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Farm Operations', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'agri'],
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Farm Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        Cache::forget('module_active_agri');

        // Farm workers' time is kept by Time & Attendance, which a farm organisation therefore runs.
        $hr = DB::table('security_categories')->where('name', 'Human Resources')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Human Resources', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(['code_name' => 'time_attendance'],
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Time & Attendance', 'category_id' => $hr, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_time_attendance');
    }

    protected function entitle(Company $company): void
    {
        foreach (['agri', 'time_attendance'] as $code) {
            DB::table('company_modules')->updateOrInsert(
                ['company_id' => $company->id, 'module_code' => $code],
                ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    protected function makeUser(string $email, ?Role $role, Company $company): User
    {
        $user = User::factory()->create(['email' => $email, 'status' => 1, 'company_id' => $company->id]);
        if ($role) {
            $user->assignRole($role);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $moduleId = DB::table('security_modules')->where('code_name', 'agri')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]
        );
        Cache::forget('hub_module_id:agri');

        return $user->fresh();
    }

    /** A farm record, filed in Document Management as the farm's manager files one. */
    protected function fileDocument(Farm $farm, string $type, string $title, ?string $expires = null, ?User $by = null, ?string $content = null): \App\Modules\Operations\DocumentManagement\Models\Document
    {
        $by ??= $this->manager;
        $this->actingAs($by);

        return $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\FarmDocuments::class)->file($farm, ['document_type' => $type, 'title' => $title, 'expires_on' => $expires],
            ['original_name' => \Illuminate\Support\Str::slug($title).'.pdf', 'mime' => 'application/pdf', 'data' => base64_encode($content ?? "%PDF-1.4\n% {$title}\n")], $by));
    }

    protected function asCompany(Company $company, callable $callback): mixed
    {
        $current = app(CurrentCompany::class);
        $current->set($company->id);
        try {
            return $callback();
        } finally {
            $current->clear();
        }
    }

    protected function farm(string $code, ?Company $company = null, string $status = Farm::ACTIVE): Farm
    {
        $company ??= $this->company;

        return $this->asCompany($company, fn () => Farm::withoutGlobalScope('farm_access')->create([
            'company_id' => $company->id, 'code' => $code, 'name' => "Farm {$code}", 'farm_type' => 'mixed',
            'area_unit' => 'ha', 'timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR', 'status' => $status,
        ]));
    }

    protected function member(User $user, Farm $farm, string $role = 'manager', string $status = FarmMembership::ACTIVE): FarmMembership
    {
        $m = FarmMembership::withoutGlobalScopes()->create([
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'user_id' => $user->id,
            'role' => $role, 'status' => $status,
        ]);
        app(FarmAccess::class)->flush();

        return $m;
    }

    /** Farm codes on the register page as the given user sees them. */
    protected function farmsSeenBy(User $user, array $query = ['status' => 'all']): array
    {
        app(FarmAccess::class)->flush();

        return array_column(
            $this->actingAs($user)->get(route('agri.farms.index', $query))->assertOk()->viewData('page')['props']['farms'],
            'code'
        );
    }
}
