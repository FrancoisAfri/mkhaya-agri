<?php

namespace Tests\Feature\PreBoarding;

use App\Modules\HR\PreBoarding\Actions\CompletePreBoardingTaskAction;
use App\Modules\HR\PreBoarding\Enums\PreBoardingStatus;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskStatus;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use Illuminate\Validation\ValidationException;

class CompletePreBoardingTaskActionTest extends PreBoardingTestCase
{
    private CompletePreBoardingTaskAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(CompletePreBoardingTaskAction::class);
    }

    public function test_marks_task_completed(): void
    {
        $plan = PreBoardingPlan::factory()->create();
        $task = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->action->execute($task, $this->hrManager->id);

        $this->assertEquals(PreBoardingTaskStatus::Completed, $task->fresh()->status);
    }

    public function test_marks_task_skipped_when_skip_flag_set(): void
    {
        $plan = PreBoardingPlan::factory()->create();
        $task = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->action->execute($task, $this->hrManager->id, skip: true);

        $this->assertEquals(PreBoardingTaskStatus::Skipped, $task->fresh()->status);
    }

    public function test_sets_completed_at_and_completed_by(): void
    {
        $plan = PreBoardingPlan::factory()->create();
        $task = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->action->execute($task, $this->hrManager->id);

        $fresh = $task->fresh();
        $this->assertNotNull($fresh->completed_at);
        $this->assertEquals($this->hrManager->id, $fresh->completed_by);
    }

    public function test_advances_plan_to_in_progress_when_first_task_done(): void
    {
        $plan  = PreBoardingPlan::factory()->create();
        $task1 = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id, 'sort_order' => 0]);
        $task2 = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id, 'sort_order' => 1]);

        $this->action->execute($task1, $this->hrManager->id);

        $this->assertEquals(PreBoardingStatus::InProgress, $plan->fresh()->status);
    }

    public function test_completes_plan_when_all_tasks_done(): void
    {
        $plan = PreBoardingPlan::factory()->create();
        $task = PreBoardingTask::factory()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->action->execute($task, $this->hrManager->id);

        $fresh = $plan->fresh();
        $this->assertEquals(PreBoardingStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_throws_when_task_already_completed(): void
    {
        $plan = PreBoardingPlan::factory()->create();
        $task = PreBoardingTask::factory()->completed()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($task, $this->hrManager->id);
    }

    public function test_throws_when_task_already_skipped(): void
    {
        $plan = PreBoardingPlan::factory()->create();
        $task = PreBoardingTask::factory()->skipped()->create(['pre_boarding_plan_id' => $plan->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($task, $this->hrManager->id);
    }
}
