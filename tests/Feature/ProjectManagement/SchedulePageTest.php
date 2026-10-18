<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Baseline;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\DependencyService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;

/**
 * The schedule page over HTTP (PM-1230, PM-1233, PM-1235).
 *
 * Two things this has to get right, beyond rendering: rescheduling is its own grant, because it
 * moves every date on the job; and opening the page must not quietly recalculate, or looking at a
 * schedule would change it.
 */
class SchedulePageTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['planned_start' => '2026-03-02 08:00']);
    }

    private function task(string $name, int $days): Task
    {
        return app(TaskService::class)->create($this->project, [
            'name' => $name, 'duration_minutes' => $days * 9 * 60,
        ]);
    }

    private function props(): array
    {
        return $this->get(route('project-management.schedule', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_the_page_shows_float_in_days_and_marks_the_critical_path(): void
    {
        $a = $this->task('Excavate', 5);
        $b = $this->task('Pour', 5);
        $this->task('Order signage', 1);
        app(DependencyService::class)->link($a, $b);

        $this->actingAs($this->manager)
            ->post(route('project-management.schedule.run', $this->project->uuid))
            ->assertRedirect();

        $rows = collect($this->actingAs($this->manager)->props()['tasks'])->keyBy('name');

        $this->assertTrue($rows['Excavate']['is_critical']);
        $this->assertSame(0.0, $rows['Excavate']['float_days']);
        // Days on the project's own nine-hour calendar, not minutes and not eight-hour days.
        $this->assertSame(5.0, $rows['Excavate']['duration_days']);
        $this->assertGreaterThan(0, $rows['Order signage']['float_days']);
    }

    /**
     * Opening a schedule must not change it. If the page recalculated on load, two people looking
     * at the same plan could see different dates and neither would have touched anything.
     */
    public function test_opening_the_page_does_not_recalculate(): void
    {
        $task = $this->task('Excavate', 3);

        $this->actingAs($this->manager)->props();

        $this->assertNull($task->fresh()->scheduled_at);
        $this->assertFalse($this->actingAs($this->manager)->props()['tasks'][0]['scheduled']);
    }

    public function test_the_page_says_when_nothing_has_been_scheduled_yet(): void
    {
        $this->task('Excavate', 3);

        $props = $this->actingAs($this->manager)->props();

        $this->assertNull($props['lastRun']);
        $this->assertNull($props['baseline']);
        // Not zero variance — that would read as "on plan", which is a claim nobody has earned.
        $this->assertNull($props['variance']['finish_days']);
    }

    public function test_a_conflict_comes_back_as_a_readable_message(): void
    {
        $a = $this->task('Excavate', 10);
        $b = $this->task('Pour', 2);
        app(DependencyService::class)->link($a, $b);
        $b->forceFill(['constraint_type' => 'mso', 'constraint_date' => '2026-03-05'])->save();

        $this->actingAs($this->manager)
            ->post(route('project-management.schedule.run', $this->project->uuid))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Excavate'));
    }

    public function test_a_baseline_can_be_taken_and_the_second_one_needs_a_reason(): void
    {
        $this->task('Excavate', 3);

        $this->actingAs($this->manager)
            ->post(route('project-management.baselines.store', $this->project->uuid), ['name' => 'Contract award'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('project-management.baselines.store', $this->project->uuid), ['name' => 'Rev B'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, Baseline::count());

        $this->actingAs($this->manager)
            ->post(route('project-management.baselines.store', $this->project->uuid), [
                'name' => 'Rev B', 'reason' => 'Client added a level.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Baseline::count());
    }

    public function test_variance_appears_once_there_is_something_to_compare(): void
    {
        $task = $this->task('Excavate', 3);
        app(TaskService::class)->update($task, ['planned_end' => '2026-03-04 15:00']);

        $this->actingAs($this->manager)
            ->post(route('project-management.baselines.store', $this->project->uuid), ['name' => 'Contract award']);

        app(TaskService::class)->update($task->fresh(), ['planned_end' => '2026-03-06 15:00']);

        $props = $this->actingAs($this->manager)->props();

        $this->assertSame(2.0, $props['variance']['finish_days']);
        $this->assertSame('late', collect($props['variance']['tasks'])->firstWhere('name', 'Excavate')['state']);
    }

    // ── Who may reschedule ───────────────────────────────────────────────────

    public function test_rescheduling_needs_the_schedule_grant(): void
    {
        $this->task('Excavate', 3);

        // A field worker may see the plan and may not move it.
        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.schedule', $this->project->uuid))
            ->assertOk();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.schedule.run', $this->project->uuid))
            ->assertForbidden();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.baselines.store', $this->project->uuid), ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertSame(0, Baseline::count());
    }

    public function test_someone_with_no_grants_cannot_see_the_schedule_at_all(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('project-management.schedule', $this->project->uuid))
            ->assertForbidden();
    }
}
