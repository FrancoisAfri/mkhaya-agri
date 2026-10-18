<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Enums\OnboardingStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskStatus;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    private User $hrManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateBoardingModule();

        foreach (['view_onboarding', 'manage_onboarding', 'view_pre_boarding', 'manage_pre_boarding'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'hr_manager', 'guard_name' => 'web']);
        $role->syncPermissions(['view_onboarding', 'manage_onboarding', 'view_pre_boarding', 'manage_pre_boarding']);

        $this->hrManager = User::factory()->create();
        $this->hrManager->assignRole('hr_manager');
        $this->grantBoardingTier($this->hrManager);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_inertia_page(): void
    {
        OnboardingPlan::factory()->count(2)->create();

        $this->actingAs($this->hrManager)
            ->get(route('onboarding.index'))
            ->assertInertia(fn($page) => $page->component('Modules/Onboarding/Index'));
    }

    public function test_index_requires_auth(): void
    {
        $this->get(route('onboarding.index'))->assertRedirect(route('login'));
    }

    public function test_index_filters_by_status(): void
    {
        OnboardingPlan::factory()->create(['status' => 'active']);
        OnboardingPlan::factory()->completed()->create();

        $response = $this->actingAs($this->hrManager)
            ->get(route('onboarding.index', ['status' => 'active']));

        $response->assertInertia(fn($page) =>
            $page->component('Modules/Onboarding/Index')
                 ->has('plans.data', 1)
        );
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_onboarding_plan(): void
    {
        $person = HrPerson::factory()->create();

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.plans.store', $person->id), [
                'start_date' => now()->addDay()->toDateString(),
                'end_date'   => now()->addDays(91)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('onboarding_plans', [
            'hr_person_id' => $person->id,
            'status'       => OnboardingStatus::Active->value,
        ]);
    }

    public function test_store_seeds_default_tasks_when_none_provided(): void
    {
        $person = HrPerson::factory()->create();

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.plans.store', $person->id), [
                'start_date' => now()->addDay()->toDateString(),
                'end_date'   => now()->addDays(91)->toDateString(),
            ]);

        $plan = OnboardingPlan::where('hr_person_id', $person->id)->first();
        $this->assertGreaterThan(0, $plan->tasks()->count());
    }

    public function test_store_validates_end_date_after_start_date(): void
    {
        $person = HrPerson::factory()->create();

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.plans.store', $person->id), [
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date'   => now()->addDays(5)->toDateString(),
            ])
            ->assertSessionHasErrors('end_date');
    }

    public function test_store_rejects_duplicate_active_plan(): void
    {
        $person = HrPerson::factory()->create();
        OnboardingPlan::factory()->create(['hr_person_id' => $person->id, 'status' => 'active']);

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.plans.store', $person->id), [
                'start_date' => now()->addDay()->toDateString(),
                'end_date'   => now()->addDays(91)->toDateString(),
            ])
            ->assertSessionHasErrors('hr_person_id');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_plan_with_tasks(): void
    {
        $plan = OnboardingPlan::factory()->create();
        OnboardingTask::factory()->count(3)->create(['onboarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->get(route('onboarding.plans.show', [$plan->hr_person_id, $plan->id]))
            ->assertInertia(fn($page) =>
                $page->component('Modules/Onboarding/Show')
                     ->has('plan.id')
                     ->has('plan.tasks', 3)
            );
    }

    public function test_show_returns_404_for_wrong_person_scope(): void
    {
        $person = HrPerson::factory()->create();
        $other  = HrPerson::factory()->create();
        $plan   = OnboardingPlan::factory()->create(['hr_person_id' => $other->id]);

        $this->actingAs($this->hrManager)
            ->get(route('onboarding.plans.show', [$person->id, $plan->id]))
            ->assertStatus(404);
    }

    // ── Complete task ─────────────────────────────────────────────────────────

    public function test_complete_task_marks_completed(): void
    {
        $plan = OnboardingPlan::factory()->create();
        $task = OnboardingTask::factory()->create(['onboarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.tasks.complete', [$plan->hr_person_id, $plan->id, $task->id]))
            ->assertRedirect();

        $this->assertEquals(OnboardingTaskStatus::Completed, $task->fresh()->status);
    }

    public function test_complete_task_with_skip_marks_skipped(): void
    {
        $plan = OnboardingPlan::factory()->create();
        $task = OnboardingTask::factory()->create(['onboarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.tasks.complete', [$plan->hr_person_id, $plan->id, $task->id]), ['skip' => 1])
            ->assertRedirect();

        $this->assertEquals(OnboardingTaskStatus::Skipped, $task->fresh()->status);
    }

    public function test_all_tasks_done_marks_plan_completed(): void
    {
        $plan = OnboardingPlan::factory()->create();
        $task = OnboardingTask::factory()->create(['onboarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.tasks.complete', [$plan->hr_person_id, $plan->id, $task->id]));

        $this->assertEquals(OnboardingStatus::Completed, $plan->fresh()->status);
    }

    public function test_complete_task_returns_404_for_wrong_plan_scope(): void
    {
        $plan  = OnboardingPlan::factory()->create();
        $other = OnboardingPlan::factory()->create();
        $task  = OnboardingTask::factory()->create(['onboarding_plan_id' => $other->id]);

        $this->actingAs($this->hrManager)
            ->post(route('onboarding.tasks.complete', [$plan->hr_person_id, $plan->id, $task->id]))
            ->assertStatus(404);
    }
}
