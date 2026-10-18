<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Core\Role\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

abstract class PayrollTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $hrManager;
    protected User $financeManager;
    protected User $employee;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.pages.paths' => [resource_path('js')]]);
        app()->useStoragePath(sys_get_temp_dir() . '/mkhaya-payroll-test-' . uniqid());

        $this->seedModule();
        $this->seedRoles();
        $this->createUsers();
    }

    protected function seedModule(): void
    {
        // Activate the payroll module so the middleware chain passes.
        //
        // The comment that used to be here said the payroll routes use only 'web' and 'auth'. That
        // stopped being true when the hub tiers were introduced: every payroll URL now also passes
        // module.active and module.tier, and PayrollHubs::guards() floors the whole module at ADMIN.
        // security_modules.category_id is NOT NULL, so ensure a category exists and reference it.
        $categoryId = DB::table('security_categories')->value('id');
        if (! $categoryId) {
            $categoryId = DB::table('security_categories')->insertGetId([
                'name' => 'Human Resources', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('security_modules')->insertOrIgnore([
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'code_name' => 'payroll', 'category_id' => $categoryId, 'active' => 1, 'name' => 'Payroll', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Cache::forget('module_active_payroll');
    }

    protected function seedRoles(): void
    {
        foreach (['hr manager', 'finance manager', 'administrator', 'super admin', 'employee'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // The roles above were created empty, holding no permissions at all, and EnforceCrudPermissions
        // derives a permission from every route name — so `/payroll/runs` asked for one of
        // [payroll.runs.index, payroll.runs.view, view] and the 'hr manager' got none of them. Every
        // screen answered 403 and thirteen tests had been failing on it.
        //
        // It was only ever a fixture problem. 'hr manager' is not a role this product ships: the real
        // ones are 'payroll officer', 'HR' and 'manager', and all of them hold the generic `view`
        // permission, which is what lets them open a payroll screen in a real deployment. The
        // fixtures are given the same thing rather than the guard being loosened.
        // The generic action permissions the middleware falls back to. A GET derives `view`, a POST
        // `create`/`modify`/`update`, a DELETE `delete` — so a fixture that only holds `view` passes
        // every screen and fails every button on it.
        $generic = collect(['view', 'create', 'update', 'modify', 'delete'])
            ->map(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $payroll = collect([
            'payroll.runs.create', 'payroll.runs.calculate', 'payroll.runs.lock',
            'payroll.runs.submit', 'payroll.runs.release', 'payroll.runs.cancel',
            'payroll.anomalies.manage', 'payroll.approvals.manage', 'payroll.settings.manage',
        ])->map(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        foreach (['hr manager', 'finance manager', 'administrator', 'super admin'] as $name) {
            Role::findByName($name, 'web')->syncPermissions($payroll->merge($generic));
        }
    }

    protected function createUsers(): void
    {
        $this->hrManager = User::factory()->create(['status' => 1]);
        $this->hrManager->assignRole('hr manager');
        $this->grantModuleAccess($this->hrManager, ModuleHubs::ADMIN);

        $this->financeManager = User::factory()->create(['status' => 1]);
        $this->financeManager->assignRole('finance manager');
        $this->grantModuleAccess($this->financeManager, ModuleHubs::ADMIN);

        // Deliberately left with no module access: an ordinary employee reaching a payroll run is
        // the thing the tier floor exists to prevent, and a test that granted it would stop
        // checking.
        $this->employee = User::factory()->create(['status' => 1]);
        $this->employee->assignRole('employee');

        $this->admin = User::factory()->create(['status' => 1]);
        $this->admin->assignRole('administrator');
        $this->grantModuleAccess($this->admin, ModuleHubs::SUPERUSER);
    }

    /**
     * Give a user their payroll access level.
     *
     * Access is per user in `security_module_access`, not per Spatie role — HubAccess::level()
     * reads that table and falls back to READ. Assigning the 'hr manager' role alone therefore left
     * every one of these users on READ, and the ADMIN floor on payroll/* answered 403. The tests
     * were written before the tiers existed and had been failing on it ever since.
     */
    protected function grantModuleAccess(User $user, int $level): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'payroll')->value('id');

        if (! $moduleId) {
            return;
        }

        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => $level, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );

        Cache::forget("hub_module_id:payroll");
    }
}
