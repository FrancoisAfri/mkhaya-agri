<?php

namespace Tests\Feature\Fleet;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use App\Support\Hubs\ModuleHubs;
use Tests\TestCase;

abstract class FleetTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $fleetManager;
    protected User $fleetDriver;
    protected User $fleetViewer;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.pages.paths' => [resource_path('js')]]);
        app()->useStoragePath(sys_get_temp_dir() . '/mkhaya-fleet-test-' . uniqid());

        $this->activateModule();
        $this->seedFleetSettings();
        $this->seedPermissionsAndRoles();
        $this->createUsers();
    }

    /**
     * The module has to be active before any of its routes answer.
     *
     * `module.active:fleet` redirects to the dashboard otherwise — a 302 with no error bag, which
     * `assertRedirect()` happily accepts, so the tests looked like they were posting successfully
     * while the controller never ran and the table stayed empty.
     */
    protected function activateModule(): void
    {
        $categoryId = \Illuminate\Support\Facades\DB::table('security_categories')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('security_categories')->insertGetId([
                'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
            ]);

        \Illuminate\Support\Facades\DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'fleet_management'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Fleet',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        \Illuminate\Support\Facades\Cache::flush();
    }

    protected function seedFleetSettings(): void
    {
        \Illuminate\Support\Facades\DB::table('fleet_settings')->insertOrIgnore([
            ['key' => 'new_vehicle_approval', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'booking_approval_required', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        ]);

        /*
         * At least one approval step, because the booking controller refuses without one.
         *
         * `store()` checks FleetBookingApprovalConfig for an active step and rejects the booking
         * with "No approval workflow has been configured" if it finds none — which is the right
         * behaviour for a real installation and leaves every booking test failing on a database
         * that has never had the module set up.
         */
        \Illuminate\Support\Facades\DB::table('fleet_booking_approval_configs')->insertOrIgnore([
            [
                'step_number' => 1, 'approver_type' => 'role', 'role_name' => 'fleet_manager',
                'check_local_trip' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Permissions come from the real seeder, not a copy of it.
     *
     * This used to keep its own list, and it had drifted: the controllers check
     * `fleet.vehicles.edit`, `fleet.drivers.edit`, `fleet.cards.transactions.reconcile` and a
     * dozen more that the copy never created, so fifteen tests failed with 403 against a module
     * that works. Seeding the catalogue the application actually ships means the tests cannot
     * disagree with production about what a permission is called.
     */
    protected function seedPermissionsAndRoles(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $fleetPermissions = Permission::where('name', 'like', 'fleet.%')->pluck('name')->all();

        /*
         * The bare action verbs EnforceCrudPermissions falls back to.
         *
         * It builds its candidates from the route name and HTTP verb, and many fleet routes are
         * named for what they do rather than for CRUD — `bulk-approve`, `check-in`, `cancel`. When
         * no namespaced permission matches, the chain ends at the bare verb.
         *
         * `delete` is deliberately absent: Spatie registers a Gate::before granting any ability
         * whose name matches a held permission, so a bare `delete` would bypass DELETE policies.
         */
        foreach (['view', 'create', 'update'] as $verb) {
            Permission::firstOrCreate(['name' => $verb, 'guard_name' => 'web']);
        }

        $managerRole = Role::firstOrCreate(['name' => 'fleet_manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions(array_merge($fleetPermissions, ['view', 'create', 'update']));

        $driverRole = Role::firstOrCreate(['name' => 'fleet_driver', 'guard_name' => 'web']);
        $driverRole->syncPermissions([
            'fleet.dashboard.view',
            'fleet.bookings.view',
            'fleet.bookings.create',
            // A driver calls off their own trip; the controller still checks they own it.
            'fleet.bookings.cancel',
            'fleet.vehicles.view',
            'view',
            'create',
            'update',
        ]);

        $viewerRole = Role::firstOrCreate(['name' => 'fleet_viewer', 'guard_name' => 'web']);
        $viewerRole->syncPermissions([
            'fleet.dashboard.view',
            'fleet.vehicles.view',
            'fleet.bookings.view',
            'fleet.documents.view',
            'fleet.reports.view',
            'view',
        ]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function createUsers(): void
    {
        $this->fleetManager = User::factory()->create(['status' => 1]);
        $this->fleetManager->assignRole('fleet_manager');
        $this->grantHubTier($this->fleetManager, ModuleHubs::ADMIN);

        $this->fleetDriver = User::factory()->create(['status' => 1]);
        $this->fleetDriver->assignRole('fleet_driver');
        $this->grantHubTier($this->fleetDriver, ModuleHubs::WRITE);

        $this->fleetViewer = User::factory()->create(['status' => 1]);
        $this->fleetViewer->assignRole('fleet_viewer');
        $this->grantHubTier($this->fleetViewer, ModuleHubs::READ);

        $this->admin = User::factory()->create(['status' => 1]);
        $this->admin->assignRole('fleet_manager');
        $this->grantHubTier($this->admin, ModuleHubs::ADMIN);
    }

    /**
     * The hub tier that `module.tier:fleet_management` enforces.
     *
     * Separate from the Spatie permissions above and enforced by a different middleware: a role
     * says what you may do, the tier says which hub of the module you may be in at all. Without a
     * row here every user sits at READ, so a driver holding fleet.bookings.create was still turned
     * away from the booking screen with a 403.
     */
    protected function grantHubTier(User $user, int $level): void
    {
        $moduleId = \Illuminate\Support\Facades\DB::table('security_modules')
            ->where('code_name', 'fleet_management')->value('id');

        if (! $moduleId) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => $level, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );

        \Illuminate\Support\Facades\Cache::flush();
    }
}
