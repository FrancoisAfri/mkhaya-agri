<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Core\Role\Models\Role;
use Tests\TestCase;

abstract class FinanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $financeManager;
    protected User $hrManager;
    protected User $employee;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedRoles();
        $this->seedPermissions();
        $this->createUsers();
    }

    /**
     * Insert the minimum security_categories + security_modules rows so that
     * the module.active middleware does not redirect tests to the dashboard.
     */
    protected function seedModules(): void
    {
        $categoryId = DB::table('security_categories')->insertGetId([
            'name'       => 'Finance',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_modules')->insertOrIgnore([
            [
                'category_id' => $categoryId,
                'uuid'        => \Illuminate\Support\Str::uuid(),
                'name'        => 'Claims',
                'path'        => 'claims',
                'code_name'   => 'claims',
                'active'      => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'category_id' => $categoryId,
                'uuid'        => \Illuminate\Support\Str::uuid(),
                'name'        => 'Petty Cash',
                'path'        => 'petty-cash',
                'code_name'   => 'petty_cash',
                'active'      => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    /**
     * The permissions `EnforceCrudPermissions` looks for.
     *
     * The roles above existed with nothing attached, so every claims and petty-cash screen
     * answered 403 — the middleware builds its candidates from the route name and HTTP verb and
     * found the user holding none of them.
     *
     * The namespaced permissions come first and the bare verbs are the documented fallback. Note
     * that `delete` is deliberately absent: Spatie registers a Gate::before granting any ability
     * whose name matches a held permission, so a bare `delete` would bypass any policy on a DELETE
     * route.
     */
    protected function seedPermissions(): void
    {
        $permissions = [
            'claims.view', 'claims.create', 'claims.update', 'claims.approve',
            'claims.my.view', 'claims.my.create', 'claims.my.update',
            'petty_cash.view', 'petty_cash.create', 'petty_cash.update', 'petty_cash.approve',
            'staff_loans.view', 'staff_loans.create', 'staff_loans.update', 'staff_loans.approve',
            'view', 'create', 'update',
        ];

        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['administrator', 'finance manager', 'hr manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->syncPermissions($permissions);
        }

        // An employee raises their own claims and requests; they approve nothing.
        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web'])->syncPermissions([
            'claims.view', 'claims.create', 'claims.update',
            'claims.my.view', 'claims.my.create', 'claims.my.update',
            'petty_cash.view', 'petty_cash.create',
            'staff_loans.view', 'staff_loans.create',
            'view', 'create', 'update',
        ]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super admin',     'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'administrator',   'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'finance manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'hr manager',      'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee',        'guard_name' => 'web']);
    }

    protected function createUsers(): void
    {
        $this->admin = User::factory()->create(['status' => 1]);
        $this->admin->assignRole('administrator');

        $this->financeManager = User::factory()->create(['status' => 1]);
        $this->financeManager->assignRole('finance manager');

        $this->hrManager = User::factory()->create(['status' => 1]);
        $this->hrManager->assignRole('hr manager');

        $this->employee = User::factory()->create(['status' => 1]);
        $this->employee->assignRole('employee');
    }
}
