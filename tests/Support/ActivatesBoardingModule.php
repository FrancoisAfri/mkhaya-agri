<?php

namespace Tests\Support;

use App\Core\Role\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts the Boarding module into the state a real request needs before it can reach a controller.
 *
 * Without this the whole suite fails for a reason that has nothing to do with the code under test.
 * `EnsureModuleIsActive` reads `security_modules`, which RefreshDatabase leaves empty, so every
 * request is redirected to the dashboard — and `assertInertia` then reports "Not a valid Inertia
 * response", which reads like a broken controller rather than a module that was never switched on.
 * `EnsureModuleTier` does the same for `security_module_access`.
 *
 * Twenty-four of the module's thirty-five tests were failing this way, so its real behaviour had
 * been unverified for as long as the middleware has been in front of it.
 */
trait ActivatesBoardingModule
{
    /** Pre-boarding and onboarding share one module row. */
    protected string $boardingModuleCode = 'boarding';

    protected function activateBoardingModule(): void
    {
        // Tests render controllers and payloads, not asset bundles. Without this they render the
        // real Blade shell and 500 with "Vite manifest not found" whenever public/build is absent
        // or a production build is part-way through writing it.
        $this->withoutVite();

        $categoryId = DB::table('security_categories')->where('name', 'Human Resources')->value('id')
            ?? DB::table('security_categories')->insertGetId([
                'name' => 'Human Resources', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => $this->boardingModuleCode],
            [
                'uuid' => (string) Str::uuid(), 'name' => 'Boarding',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        Cache::forget("module_active_{$this->boardingModuleCode}");
        Cache::forget("hub_module_id:{$this->boardingModuleCode}");
    }

    /**
     * Grant the CRUD permission the routes actually name.
     *
     * There are two EnforceCrudPermissions in the stack and they want different things. The one
     * named on the route wants `boarding.manage`. The un-argumented one runs first and *derives*
     * candidates from the route name — for `onboarding.index` it accepts `onboarding.index`,
     * `onboarding.view` or the bare action `view`. The suite seeded `view_onboarding` /
     * `manage_onboarding`, which satisfies neither, so every request was a 403 and the assertions
     * failed downstream of it: "not a valid Inertia response" for a page that never rendered, and
     * null models from a store that never ran.
     *
     * Granting the bare actions covers every derived check without hard-coding a permission per
     * route, which would rot the moment a route is added.
     */
    protected function grantBoardingPermissions(object $user): void
    {
        foreach (['view', 'create', 'update', 'delete', 'boarding.view', 'boarding.manage'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'boarding_test_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['view', 'create', 'update', 'delete', 'boarding.view', 'boarding.manage']);

        $user->assignRole($role);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Give a user the top module tier.
     *
     * Tier and permission are separate gates. These tests are about the permissions, so the tier is
     * granted outright — a tier redirect masquerading as a permission decision is exactly the kind
     * of false pass that makes a suite worthless.
     */
    protected function grantBoardingTier(object $user, int $level = 5): void
    {
        $moduleId = DB::table('security_modules')
            ->where('code_name', $this->boardingModuleCode)
            ->value('id');

        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => $level, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->grantBoardingPermissions($user);
    }
}
