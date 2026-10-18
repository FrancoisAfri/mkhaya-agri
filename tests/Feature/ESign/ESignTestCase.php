<?php

namespace Tests\Feature\ESign;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ESignTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $hrUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hrUser = User::factory()->create();

        $this->grantCrudPermissions($this->hrUser);
    }

    /**
     * Give the test user the permissions the routes actually check.
     *
     * The esign routes carry an un-argumented EnforceCrudPermissions, which *derives* its candidates
     * from the route name — for `esign.envelopes.index` it accepts `esign.envelopes.index`,
     * `esign.envelopes.view`, or the bare action `view`. The suite granted none of them, so all 28
     * tests were really testing the 403 page rather than the controller behind it.
     *
     * Granting the bare actions covers every derived check without naming a permission per route,
     * which would rot the moment a route is added.
     */
    protected function grantCrudPermissions(User $user): void
    {
        foreach (['view', 'create', 'update', 'delete'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'esign_test_user', 'guard_name' => 'web']);
        $role->givePermissionTo(['view', 'create', 'update', 'delete']);

        $user->assignRole($role);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
