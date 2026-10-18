<?php

namespace Tests\Feature\Recruitment;

use App\Core\User\Models\User;
use App\Modules\Policies\Services\EnforcementEngine\PolicyEventDispatcher;
use App\Modules\Policies\Services\ModuleActionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use Tests\TestCase;

abstract class RecruitmentTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $recruiter;
    protected User $hrManager;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassPolicyValidation();
        $this->activateRecruitmentModule();
        $this->seedPermissionsAndRoles();
        $this->createUsers();
    }

    /**
     * The recruitment routes are gated by `module.active:recruitment`, which reads
     * the security_modules table (cached). RefreshDatabase leaves it empty, so
     * activate the module here or every request 403s ("module not active").
     */
    protected function activateRecruitmentModule(): void
    {
        $categoryId = \Illuminate\Support\Facades\DB::table('security_categories')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('security_categories')->insertGetId([
                'name' => 'Human Resources', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        \Illuminate\Support\Facades\DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'recruitment'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Recruitment',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        \Illuminate\Support\Facades\Cache::forget('module_active_recruitment');
    }

    /**
     * Bypass PolicyValidationTrait so action tests don't depend on policy engine state.
     * Individual tests that want to assert policy blocking can override these mocks.
     */
    protected function bypassPolicyValidation(): void
    {
        $this->mock(ModuleActionValidator::class, function ($mock) {
            $mock->shouldReceive('isActionAllowed')->andReturn(true);
            $mock->shouldReceive('getViolationDetails')->andReturn([
                'violations'   => [],
                'is_blocked'   => false,
                'block_reason' => null,
            ]);
        });

        $this->mock(PolicyEventDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andReturn(null);
        });
    }

    protected function seedPermissionsAndRoles(): void
    {
        $permissions = [
            'recruitment.jobs.view',
            'recruitment.jobs.create',
            'recruitment.jobs.edit',
            'recruitment.jobs.delete',
            'recruitment.candidates.view',
            'recruitment.candidates.create',
            'recruitment.candidates.edit',
            'recruitment.candidates.anonymise',
            'recruitment.candidates.upload_cv',
            'recruitment.applications.view',
            'recruitment.applications.move',
            'recruitment.requisitions.view',
            'recruitment.requisitions.create',
            'recruitment.requisitions.approve',
            'recruitment.shortlists.view',
            'recruitment.shortlists.create',
            'recruitment.shortlists.approve',
            'recruitment.shortlists.override',
            'recruitment.interviews.view',
            'recruitment.interviews.create',
            'recruitment.interviews.score',
            // The end of the funnel — OfferPolicy and ContractPolicy check these.
            'offers.view',
            'offers.create',
            'offers.send',
            'offers.accept',
            'contracts.view',
            'contracts.create',
            'contracts.send',
            // EnforceCrudPermissions derives candidate permissions from the route
            // name + HTTP verb and always falls back to the bare action verb.
            // Seeding these makes the module's CRUD routes reachable for the test
            // roles (business-rule 403s in controllers still apply).
            // NOTE: 'delete' is deliberately omitted — Spatie registers a
            // Gate::before that grants any ability matching a held permission, so a
            // bare 'delete' permission would bypass JobPolicy::delete. DELETE routes
            // instead authorise via the namespaced 'recruitment.jobs.delete'.
            'view',
            'create',
            'update',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $recruiterRole = Role::firstOrCreate(['name' => 'recruiter', 'guard_name' => 'web']);
        $recruiterRole->syncPermissions([
            'recruitment.jobs.view',
            'recruitment.jobs.create',
            'recruitment.jobs.edit',
            'recruitment.candidates.view',
            'recruitment.candidates.create',
            'recruitment.candidates.edit',
            'recruitment.candidates.upload_cv',
            'recruitment.applications.view',
            'recruitment.applications.move',
            'recruitment.requisitions.view',
            'recruitment.requisitions.create',
            'recruitment.shortlists.view',
            'recruitment.shortlists.create',
            'recruitment.shortlists.override',
            'recruitment.interviews.view',
            'recruitment.interviews.create',
            'recruitment.interviews.score',
            // The end of the funnel. OfferPolicy and ContractPolicy have always checked these;
            // until they were seeded, Spatie threw rather than denying and the screens answered
            // 500. An HR manager who can run a job can make and send its offer.
            'offers.view',
            'offers.create',
            'offers.send',
            'offers.accept',
            'contracts.view',
            'contracts.create',
            'contracts.send',
            'view',
            'create',
            'update',
        ]);

        $managerRole = Role::firstOrCreate(['name' => 'hr_manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions($permissions);

        // RefreshDatabase truncates the permission tables each test, but Spatie's
        // permission map is cached in-process — flush it so freshly-seeded
        // permissions/roles are visible to hasPermissionTo() during the request.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function createUsers(): void
    {
        $this->recruiter = User::factory()->create(['status' => 1]);
        $this->recruiter->assignRole('recruiter');

        $this->hrManager = User::factory()->create(['status' => 1]);
        $this->hrManager->assignRole('hr_manager');

        $this->admin = User::factory()->create(['status' => 1]);
        $this->admin->assignRole('hr_manager');
    }
}
