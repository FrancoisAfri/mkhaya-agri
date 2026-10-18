<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * Shared ground for the Supplier Management suite.
 *
 * Every test in this module needs the same three things before it can say anything useful: a real
 * company to be a tenant of, a user who is inside that company and carries the module's permissions,
 * and the module itself marked active — the routes sit behind `module.active:supplier_management`,
 * so without that last step every request 403s and the suite would be testing the gate rather than
 * the feature behind it.
 *
 * Built rather than assumed. These tests have to hold on a machine whose database was just
 * refreshed, and a fixture that quietly skips when the seed data is missing is indistinguishable in
 * the summary from one that passed.
 */
abstract class SupplierTestCase extends TestCase
{
    use DatabaseTransactions, MakesCompanies;

    protected int $companyId;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->makeCompany('Supplier Suite');
        app(CurrentCompany::class)->set($this->companyId);

        $this->activateModule($this->companyId);

        // Due diligence is a paid add-on with its own entitlement, and most of this suite exercises
        // it. Granted here so every test starts from "the customer bought it"; the one test that
        // cares about the other state (DueDiligenceAddonTest) takes it away deliberately, which is
        // the only way that distinction stays honest.
        $this->activateModule($this->companyId, 'supplier_due_diligence');

        $this->user = $this->makeUser($this->companyId);
        $this->actingAs($this->user);
    }

    /**
     * A user who can exercise the module.
     *
     * The permissions are granted directly rather than through a role: Spatie roles are still global
     * in this platform, so a role created here would bleed into any other test that looks for one.
     */
    protected function makeUser(int $companyId): User
    {
        // `status` and the password timestamp are not decoration: `SecurityCheck` logs out any user
        // whose status is 0 and force-redirects one whose password has expired, so a fixture that
        // omits them never reaches the controller under test — every request lands on /login.
        $user = User::create([
            'uuid'       => (string) Str::uuid(),
            'email'      => 'supplier-'.Str::random(10).'@example.test',
            'password'   => bcrypt('secret'),
            'company_id' => $companyId,
            'status'     => 1,
        ]);

        foreach (['password_changed_at', 'password_updated_at', 'last_password_change'] as $column) {
            if (DB::getSchemaBuilder()->hasColumn('users', $column)) {
                DB::table('users')->where('id', $user->id)->update([$column => now()]);
            }
        }

        foreach ($this->modulePermissionNames() as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);

            DB::table('model_has_permissions')->insertOrIgnore([
                'permission_id' => (int) $id,
                'model_type'    => User::class,
                'model_id'      => $user->id,
            ]);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * Every permission name the module's own routes can ask for.
     *
     * `EnforceCrudPermissions` derives the name it wants from the route name and the HTTP verb —
     * `suppliers.invoices.store` on POST becomes `suppliers.invoices.create` — so a fixture that
     * grants a hand-written list of five abilities passes on the handful of routes it guessed right
     * and 403s on the rest, which reads as a broken feature rather than a thin fixture. Deriving the
     * list from the router uses the same rule the middleware does, and stays correct as routes move.
     *
     * The policy abilities (`suppliers.view` … `suppliers.approve`) are granted alongside because
     * the controllers authorise against `SupplierPolicy` after the middleware has let them through.
     */
    protected function modulePermissionNames(): array
    {
        $verbToAction = ['GET' => 'view', 'POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'];
        $actionSegments = ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy', 'delete'];

        $names = ['suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.approve'];

        foreach (app('router')->getRoutes() as $route) {
            $routeName = $route->getName();

            if (! $routeName || ! str_starts_with($routeName, 'suppliers.')) {
                continue;
            }

            $names[] = $routeName;

            $parts = explode('.', $routeName);

            if (in_array(end($parts), $actionSegments, true)) {
                array_pop($parts);
            }

            foreach ($route->methods() as $method) {
                if (isset($verbToAction[$method]) && $parts !== []) {
                    $names[] = implode('.', $parts).'.'.$verbToAction[$method];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Mark the module active for a company so `module.active` lets the request through.
     *
     * Both halves matter: `security_modules` is the catalogue row and `security_module_access` is
     * the per-company entitlement. A catalogue entry on its own is a module nobody is subscribed to.
     */
    protected function activateModule(int $companyId, string $code = 'supplier_management'): int
    {
        $moduleId = DB::table('security_modules')->where('code_name', $code)->value('id');

        if (! $moduleId) {
            $categoryId = DB::table('security_categories')->min('id')
                ?? DB::table('security_categories')->insertGetId([
                    'name' => 'Operations', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);

            $moduleId = DB::table('security_modules')->insertGetId([
                'uuid' => (string) Str::uuid(), 'name' => 'Supplier Management', 'code_name' => $code,
                'path' => '/suppliers', 'active' => 1, 'category_id' => $categoryId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // The catalogue row above only says the module exists on the platform. `module.active` also
        // asks `CompanyEntitlements` whether this tenant is entitled to it, and a new company
        // defaults to the `standard` plan, which does not list supplier_management. The production
        // way a non-enterprise tenant gets this module is the à-la-carte add-on, so the fixture buys
        // it the same way rather than reaching past the gate.
        DB::table('company_modules')->insertOrIgnore([
            'company_id'    => $companyId,
            'module_code'   => $code,
            'price_monthly' => 0,
            'source'        => 'addon',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return (int) $moduleId;
    }

    /**
     * A live general budget for the current financial year, and the Budget module switched on.
     *
     * `BudgetLedgerBridge` refuses to post at all unless `budget_management` is an active module, so
     * a test that forgets this half asserts nothing — the bridge returns "disabled" and every
     * assertion about untouched budgets passes for the wrong reason.
     */
    protected function makeBudget(float $allocated = 1_000_000): \App\Modules\Budget\Models\Budget
    {
        $this->activateModule($this->companyId, 'budget_management');

        $year = \App\Modules\Budget\Models\FinancialYear::create([
            'name'       => 'FY '.Str::random(5),
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date'   => now()->endOfYear()->toDateString(),
        ]);

        return \App\Modules\Budget\Models\Budget::create([
            'code'              => 'BGT'.Str::random(6),
            'name'              => 'Operating budget',
            'financial_year_id' => $year->id,
            'expenditure_type'  => 'opex',
            'scope_type'        => 'general',
            'allocated_amount'  => $allocated,
            'status'            => 'active',
        ]);
    }

    /** A minimally valid, active supplier belonging to the current tenant. */
    protected function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'uuid'         => (string) Str::uuid(),
            'company_name' => 'Acme Supplies '.Str::random(5),
            'email'        => 'acme-'.Str::random(6).'@example.test',
            'is_active'    => 1,
        ], $overrides));
    }
}
