<?php

namespace Tests\Feature\PreBoarding;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

abstract class PreBoardingTestCase extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    protected User $hrManager;
    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateBoardingModule();
        $this->seedPermissionsAndRoles();
        $this->createUsers();

        foreach ([$this->hrManager, $this->viewer] as $user) {
            $this->grantBoardingTier($user);
        }
    }

    protected function seedPermissionsAndRoles(): void
    {
        foreach (['view_pre_boarding', 'manage_pre_boarding', 'view_onboarding', 'manage_onboarding'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $managerRole = Role::firstOrCreate(['name' => 'hr_manager', 'guard_name' => 'web']);
        $viewerRole  = Role::firstOrCreate(['name' => 'recruiter', 'guard_name' => 'web']);

        $managerRole->syncPermissions(['view_pre_boarding', 'manage_pre_boarding', 'view_onboarding', 'manage_onboarding']);
        $viewerRole->syncPermissions(['view_pre_boarding', 'view_onboarding']);
    }

    protected function createUsers(): void
    {
        $this->hrManager = User::factory()->create();
        $this->hrManager->assignRole('hr_manager');

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('recruiter');
    }
}
