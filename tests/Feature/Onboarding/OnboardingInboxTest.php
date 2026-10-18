<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

/**
 * "What onboarding work is mine?" — a question the module could not answer.
 *
 * assigned_to has always existed but nothing read it across hires, so the only way to find your own
 * work was to open each new hire in turn.
 */
class OnboardingInboxTest extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateBoardingModule();
        $this->me = User::factory()->create();
        $this->grantBoardingTier($this->me);
        $this->me = $this->me->fresh();
    }

    private function task(array $attributes, string $planStatus = 'active'): OnboardingTask
    {
        $plan = OnboardingPlan::factory()->create(['status' => $planStatus]);

        return OnboardingTask::factory()->create(array_merge([
            'onboarding_plan_id' => $plan->id,
            'status' => 'pending',
        ], $attributes));
    }

    public function test_it_lists_only_my_open_tasks(): void
    {
        $someoneElse = User::factory()->create();

        $this->task(['assigned_to' => $this->me->id, 'due_date' => now()->addDay()->toDateString()]);
        $this->task(['assigned_to' => $someoneElse->id]);
        $this->task(['assigned_to' => $this->me->id, 'status' => 'completed']);

        $this->actingAs($this->me)
            ->get(route('onboarding.inbox'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Onboarding/Inbox')
                ->has('tasks', 1));
    }

    /** Work on a cancelled plan is nobody's problem any more. */
    public function test_tasks_on_an_inactive_plan_are_excluded(): void
    {
        $this->task(['assigned_to' => $this->me->id], 'cancelled');

        $this->actingAs($this->me)
            ->get(route('onboarding.inbox'))
            ->assertInertia(fn ($page) => $page->has('tasks', 0));
    }

    public function test_overdue_items_are_counted(): void
    {
        $this->task(['assigned_to' => $this->me->id, 'due_date' => now()->subDays(2)->toDateString()]);
        $this->task(['assigned_to' => $this->me->id, 'due_date' => now()->addDays(2)->toDateString()]);

        $this->actingAs($this->me)
            ->get(route('onboarding.inbox'))
            ->assertInertia(fn ($page) => $page->where('overdueCount', 1));
    }

    /**
     * Undated work sorts last.
     *
     * A plain ascending sort puts nulls first in Postgres, which would head the list with the least
     * urgent items — the opposite of what a working list is for.
     */
    public function test_undated_tasks_sort_after_dated_ones(): void
    {
        $this->task(['assigned_to' => $this->me->id, 'due_date' => null, 'title' => 'No date']);
        $this->task(['assigned_to' => $this->me->id, 'due_date' => now()->addDay()->toDateString(), 'title' => 'Dated']);

        $this->actingAs($this->me)
            ->get(route('onboarding.inbox'))
            ->assertInertia(fn ($page) => $page->where('tasks.0.title', 'Dated'));
    }
}
