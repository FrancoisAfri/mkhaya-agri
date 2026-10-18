<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Database\Seeders\ProjectManagementModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared setup for the Project Management suite: an active module (the routes are gated by
 * module.active, which reads security_modules and RefreshDatabase leaves empty), the module's
 * permission set, and three users at different grant levels.
 */
abstract class ProjectManagementTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $manager;      // full day-to-day grants

    protected User $fieldWorker;  // may view and record evidence, nothing else

    protected User $outsider;     // authenticated, no PM grants at all

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about controllers, policies and payloads — not asset bundling. Without
        // this they render the real Blade shell and 500 with "Vite manifest not found" whenever
        // public/build is absent or a build is part-way through writing it, which turns "did I
        // break the module?" into "has somebody run vite lately?".
        $this->withoutVite();

        $this->activateModule();
        $this->seedPermissions();
        $this->createUsers();

        foreach ([$this->manager, $this->fieldWorker, $this->outsider] as $user) {
            $this->grantModuleTier($user);
        }
    }

    /**
     * The module gate reads security_modules (cached), so without this every request 403s with
     * "module not active" and the test proves nothing about authorisation.
     */
    protected function activateModule(): void
    {
        $categoryId = DB::table('security_categories')->where('name', 'Operations & Support')->value('id')
            ?? DB::table('security_categories')->insertGetId([
                'name' => 'Operations & Support', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'project_management'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Project Management',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        Cache::forget('module_active_project_management');
    }

    protected function seedPermissions(): void
    {
        foreach (ProjectManagementModuleSeeder::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    protected function createUsers(): void
    {
        $managerRole = Role::firstOrCreate(['name' => 'pm test manager', 'guard_name' => 'web']);
        // A realistic project manager: runs the work as well as the project. Deliberately without
        // pm.evidence.verify or pm.settings.manage — those are assurance and administration, and
        // several tests rely on this role *not* having them.
        $managerRole->syncPermissions([
            'pm.projects.view', 'pm.projects.create', 'pm.projects.update', 'pm.projects.delete',
            'pm.projects.manage', 'pm.evidence.view', 'pm.evidence.record', 'pm.evidence.supersede',
            'pm.tasks.view', 'pm.tasks.create', 'pm.tasks.update', 'pm.tasks.delete',
            'pm.schedule.manage', 'pm.members.manage', 'pm.costs.view',
        ]);

        $fieldRole = Role::firstOrCreate(['name' => 'pm test field', 'guard_name' => 'web']);
        $fieldRole->syncPermissions(['pm.projects.view', 'pm.evidence.view', 'pm.evidence.record']);

        $this->manager = $this->makeUser('pm-manager@example.test', $managerRole);
        $this->fieldWorker = $this->makeUser('pm-field@example.test', $fieldRole);
        $this->outsider = $this->makeUser('pm-outsider@example.test', null);
    }

    /**
     * Hub tier (EnsureModuleTier) and permissions are separate gates. Give every test user the
     * top tier so a tier redirect can never be mistaken for a permission decision — these tests
     * are about the policies, and the tier gate has its own test.
     */
    protected function grantModuleTier(User $user, int $level = 5): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'project_management')->value('id');

        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => $level, 'created_at' => now(), 'updated_at' => now()]
        );

        Cache::forget("hub_module_id:project_management");
    }

    protected function makeUser(string $email, ?Role $role): User
    {
        $user = User::factory()->create(['email' => $email, 'status' => 1]);

        if ($role) {
            $user->assignRole($role);
        }

        // Spatie caches the permission map per request; without this the role assignment above
        // is invisible to the first can() call in a test.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * Entitle a company to Project Management.
     *
     * Companies default to the `standard` plan, whose module list does not include this module —
     * so without an explicit entitlement `module.active` correctly returns 403. Adding a
     * `company_modules` row is how a tenant buys the module à la carte, which is what a real
     * customer reaching this API would have done.
     */
    protected function entitleCompany(Company $company): void
    {
        DB::table('company_modules')->updateOrInsert(
            ['company_id' => $company->id, 'module_code' => 'project_management'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** A project belonging to the currently-active company. */
    protected function makeProject(array $attributes = []): Project
    {
        $calendar = WorkCalendar::create([
            'name'      => 'Test calendar',
            'timezone'  => 'Africa/Johannesburg',
            'work_week' => WorkCalendar::STANDARD_WEEK,
        ]);

        return Project::create(array_merge([
            'code'        => 'PRJ-' . fake()->unique()->numberBetween(1000, 9999),
            'name'        => 'Test project',
            'currency'    => 'ZAR',
            'timezone'    => 'Africa/Johannesburg',
            'calendar_id' => $calendar->id,
            'status'      => 'active',
            'stage'       => 'execution',
        ], $attributes));
    }

    /** Run a callback with a given company as the active tenant. */
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
}
