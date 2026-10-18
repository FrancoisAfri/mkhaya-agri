<?php

namespace Tests\Feature\Warehouse;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared setup for Warehouse Management & Intelligence: an entitled company, the active module,
 * and four people at deliberately different grants.
 *
 * DatabaseTransactions rather than RefreshDatabase: a refresh re-runs 1,300 migrations against the
 * shared test database and wipes it under every other suite running at the same time.
 *
 * The supervisor may block a bin but not redesign the warehouse -- the separation the location
 * controls exist for -- and several tests depend on that.
 */
abstract class WarehouseTestCase extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;

    protected User $manager;

    protected User $supervisor;

    protected User $viewer;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $categoryId = DB::table('security_categories')->where('name', 'Operations & Support')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Operations & Support', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'warehouse_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Warehouse Management & Intelligence', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::forget('module_active_warehouse_management');
        Cache::forget('hub_module_id:warehouse_management');

        foreach (RoleCatalog::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->company = Company::create(['name' => 'Depot & Dock '.Str::random(5)]);
        DB::table('company_modules')->updateOrInsert(
            ['company_id' => $this->company->id, 'module_code' => 'warehouse_management'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->manager = $this->person('manager', RoleCatalog::all());
        $this->supervisor = $this->person('supervisor', [RoleCatalog::VIEW, RoleCatalog::BLOCK_LOCATIONS]);
        $this->viewer = $this->person('viewer', [RoleCatalog::VIEW]);
        $this->outsider = $this->person('outsider', []);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    protected function person(string $name, array $permissions): User
    {
        $user = User::factory()->create([
            'email' => "wms-{$name}-".Str::random(6).'@example.test', 'status' => 1, 'company_id' => $this->company->id,
        ]);

        if ($permissions !== []) {
            $role = Role::firstOrCreate(['name' => "wms test {$name}", 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $user->assignRole($role);
        }

        $moduleId = DB::table('security_modules')->where('code_name', 'warehouse_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()],
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
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

    /** Control accounts and an open period, so the stock ledger can post. */
    protected function openBooks(): void
    {
        if (! \App\Modules\Finance\Accounting\Models\FinAccount::withoutGlobalScopes()->whereNull('company_id')->where('code', '1310')->exists()) {
            (new \Database\Seeders\FinanceGlSeeder())->run();
        }

        $this->artisan('finance:ensure-chart', ['--company' => $this->company->id, '--install' => true]);

        if (! \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::forDate(now(), null)) {
            \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    protected function stockItem(string $code, string $uom = 'ea', float $cost = 10): FinStockItem
    {
        return $this->asCompany($this->company, fn () => app(InventoryService::class)->createItem([
            'company_id' => $this->company->id, 'code' => $code, 'name' => "{$code} (test)", 'uom' => $uom,
            'cost_method' => 'weighted_avg', 'standard_cost' => $cost, 'is_active' => true,
        ]));
    }

    protected function warehouse(string $code = 'WH1', array $extra = []): FinWarehouse
    {
        return $this->asCompany($this->company, fn () => FinWarehouse::create($extra + [
            'company_id' => $this->company->id, 'code' => $code, 'name' => "Warehouse {$code}", 'is_active' => true,
        ]));
    }
}
