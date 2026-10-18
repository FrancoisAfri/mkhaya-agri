<?php

namespace Tests\Feature\PreBoarding;

use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskStatus;

class PreBoardingControllerTest extends PreBoardingTestCase
{
    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_inertia_page(): void
    {
        HrPerson::factory()->count(3)->create();

        $this->actingAs($this->hrManager)
            ->get(route('pre-boarding.index'))
            ->assertInertia(fn($page) => $page->component('Modules/PreBoarding/Index'));
    }

    public function test_index_requires_auth(): void
    {
        $this->get(route('pre-boarding.index'))->assertRedirect(route('login'));
    }

    public function test_index_filters_by_status(): void
    {
        HrPerson::factory()->create(['status' => 'pre_boarding']);
        HrPerson::factory()->active()->create();

        $response = $this->actingAs($this->hrManager)
            ->get(route('pre-boarding.index', ['status' => 'pre_boarding']));

        $response->assertInertia(fn($page) =>
            $page->component('Modules/PreBoarding/Index')
                 ->has('persons.data', 1)
        );
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_person_with_plans(): void
    {
        $person = HrPerson::factory()->create();
        $plan   = PreBoardingPlan::factory()->create(['hr_person_id' => $person->id]);
        PreBoardingTask::factory()->count(2)->create(['pre_boarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->get(route('pre-boarding.show', $person->id))
            ->assertInertia(fn($page) =>
                $page->component('Modules/PreBoarding/Show')
                     ->has('person.id')
                     ->has('person.pre_boarding_plans')
            );
    }

    public function test_show_returns_404_for_unknown_person(): void
    {
        $this->actingAs($this->hrManager)
            ->get(route('pre-boarding.show', 999999))
            ->assertStatus(404);
    }

    // ── Task completion ───────────────────────────────────────────────────────

    public function test_complete_task_marks_task_done(): void
    {
        $person = HrPerson::factory()->create();
        $plan   = PreBoardingPlan::factory()->create(['hr_person_id' => $person->id]);
        $task   = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->post(route('pre-boarding.tasks.complete', [$person->id, $plan->id, $task->id]))
            ->assertRedirect();

        $this->assertEquals(PreBoardingTaskStatus::Completed, $task->fresh()->status);
    }

    public function test_complete_task_with_skip_marks_skipped(): void
    {
        $person = HrPerson::factory()->create();
        $plan   = PreBoardingPlan::factory()->create(['hr_person_id' => $person->id]);
        $task   = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->post(route('pre-boarding.tasks.complete', [$person->id, $plan->id, $task->id]), ['skip' => 1])
            ->assertRedirect();

        $this->assertEquals(PreBoardingTaskStatus::Skipped, $task->fresh()->status);
    }

    public function test_complete_task_returns_404_when_plan_belongs_to_different_person(): void
    {
        $person   = HrPerson::factory()->create();
        $other    = HrPerson::factory()->create();
        $plan     = PreBoardingPlan::factory()->create(['hr_person_id' => $other->id]);
        $task     = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->actingAs($this->hrManager)
            ->post(route('pre-boarding.tasks.complete', [$person->id, $plan->id, $task->id]))
            ->assertStatus(404);
    }

    public function test_complete_task_requires_auth(): void
    {
        $person = HrPerson::factory()->create();
        $plan   = PreBoardingPlan::factory()->create(['hr_person_id' => $person->id]);
        $task   = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->post(route('pre-boarding.tasks.complete', [$person->id, $plan->id, $task->id]))
            ->assertRedirect(route('login'));
    }
}
