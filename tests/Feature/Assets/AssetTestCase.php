<?php

namespace Tests\Feature\Assets;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetSettingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use Tests\TestCase;

abstract class AssetTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $assetManager;
    protected User $assetViewer;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.pages.paths' => [resource_path('js')]]);
        app()->useStoragePath(sys_get_temp_dir() . '/mkhaya-test-' . uniqid());

        $this->activateModule();
        AssetSettingFactory::seedDefaults();
        $this->seedPermissionsAndRoles();
        $this->createUsers();
    }

    /**
     * The module has to be active before any of its routes answer.
     *
     * `module.active:asset_management` otherwise redirects to the dashboard — a 302 with no error
     * bag, which reads as "the endpoint did nothing" rather than "you were never let in".
     */
    protected function activateModule(): void
    {
        $categoryId = \Illuminate\Support\Facades\DB::table('security_categories')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('security_categories')->insertGetId([
                'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
            ]);

        \Illuminate\Support\Facades\DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'asset_management'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Assets',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * The hub tier `module.tier:asset_management` enforces, separately from Spatie's permissions.
     *
     * A role says what you may do; the tier says which hub of the module you may be in at all.
     * Without a row here every user sits at READ and is turned away from any write screen.
     */
    protected function grantHubTier(User $user, int $level): void
    {
        $moduleId = \Illuminate\Support\Facades\DB::table('security_modules')
            ->where('code_name', 'asset_management')->value('id');

        if (! $moduleId) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => $level, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );

        \Illuminate\Support\Facades\Cache::flush();
    }

    protected function seedPermissionsAndRoles(): void
    {
        $managerPermissions = [
            'assets.view',
            'assets.create',
            'assets.update',
            'assets.edit',
            'assets.delete',
            'assets.assign',
            'assets.assignment.return',
            'assets.maintenance',
            'assets.maintenance.view',
            'assets.maintenance.create',
            'assets.maintenance.complete',
            'assets.incidents',
            'assets.incidents.create',
            'assets.movements',
            'assets.performance',
            'assets.transfer',
            'assets.dispose',
            'assets.depreciation',
            'assets.exit-clearances',
            'assets.exit-clearances.create',
            'assets.misuse-flags',
            'assets.document-extractions',
            'assets.document-extractions.create',
            'assets.utilization-reports',
            'assets.compliance-rules',
            'assets.procurement',
            'assets.approve',
            'assets.dashboard.view',
            'assets.dashboard.feed',
        ];

        /*
         * No bare `view`/`create`/`update` here, deliberately.
         *
         * Spatie registers a Gate::before that grants any ability whose *name* matches a held
         * permission. A bare `update` would therefore make `$user->can('update', $asset)` true
         * without AssetPolicy ever running — so a disposed asset would look editable, which is the
         * one thing that policy exists to prevent. The namespaced permissions above cover the
         * routes; the policies are left to decide the rest.
         */

        $viewerPermissions = [
            'assets.view',
            'assets.maintenance.view',
        ];

        foreach (array_unique(array_merge($managerPermissions, $viewerPermissions)) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $managerRole = Role::firstOrCreate(['name' => 'asset_manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions($managerPermissions);

        $viewerRole = Role::firstOrCreate(['name' => 'asset_viewer', 'guard_name' => 'web']);
        $viewerRole->syncPermissions($viewerPermissions);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function createUsers(): void
    {
        $this->assetManager = User::factory()->create(['status' => 1]);
        $this->assetManager->assignRole('asset_manager');
        $this->grantHubTier($this->assetManager, \App\Support\Hubs\ModuleHubs::ADMIN);

        $this->assetViewer = User::factory()->create(['status' => 1]);
        $this->assetViewer->assignRole('asset_viewer');
        $this->grantHubTier($this->assetViewer, \App\Support\Hubs\ModuleHubs::READ);

        $this->admin = User::factory()->create(['status' => 1]);
        $this->admin->assignRole('asset_manager');
        $this->grantHubTier($this->admin, \App\Support\Hubs\ModuleHubs::ADMIN);
    }
}
