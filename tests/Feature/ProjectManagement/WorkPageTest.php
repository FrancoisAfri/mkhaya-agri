<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskDependency;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\WbsService;

/**
 * The Work page: the breakdown and its tasks over HTTP.
 *
 * The services are covered directly elsewhere; these tests are about what the *routes* do —
 * authorisation, that a service refusal reaches the user as a readable validation error rather
 * than a 500, and that the payload the page renders from is complete.
 */
class WorkPageTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    public function test_the_page_renders_the_tree_and_its_tasks(): void
    {
        $wbs = app(WbsService::class);
        $tasks = app(TaskService::class);

        $phase = $wbs->create($this->project, ['name' => 'Substructure']);
        $tasks->create($this->project, ['name' => 'Excavate'], null, $phase);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.work', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Substructure', $props['tree'][0]['name']);
        $this->assertSame('1', $props['tree'][0]['wbs_code']);
        $this->assertSame('Excavate', $props['tasks'][0]['name']);
        $this->assertSame($phase->uuid, $props['tasks'][0]['wbs_node_id']);
    }

    public function test_a_branch_and_a_task_can_be_added(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.wbs.store', $this->project->uuid), ['name' => 'Phase 1'])
            ->assertRedirect();

        $node = WbsNode::firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name' => 'Set out', 'wbs_node_id' => $node->uuid, 'duration_minutes' => 480,
            ])
            ->assertRedirect();

        $task = Task::firstOrFail();

        $this->assertSame('T-0001', $task->code);
        $this->assertSame($node->id, $task->wbs_node_id);
    }

    public function test_a_move_that_would_loop_comes_back_as_a_readable_error(): void
    {
        $wbs = app(WbsService::class);
        $parent = $wbs->create($this->project, ['name' => 'Phase A']);
        $child = $wbs->create($this->project, ['name' => 'Package'], $parent);

        $response = $this->actingAs($this->manager)
            ->patch(route('project-management.wbs.move', [$this->project->uuid, $parent->uuid]), [
                'parent_id' => $child->uuid,
            ]);

        // Not a 500: the service names both nodes and the page shows that sentence.
        $response->assertRedirect()->assertSessionHasErrors('parent_id');
        $this->assertStringContainsString(
            "'Phase A' cannot be moved under 'Package'",
            session('errors')->first('parent_id')
        );
    }

    public function test_a_dependency_cycle_comes_back_as_a_readable_error(): void
    {
        $tasks = app(TaskService::class);
        $a = $tasks->create($this->project, ['name' => 'Pour slab']);
        $b = $tasks->create($this->project, ['name' => 'Strike formwork']);

        $this->actingAs($this->manager)
            ->post(route('project-management.dependencies.store', $this->project->uuid), [
                'predecessor_id' => $a->uuid, 'successor_id' => $b->uuid, 'dep_type' => 'fs',
            ])->assertRedirect();

        $response = $this->actingAs($this->manager)
            ->post(route('project-management.dependencies.store', $this->project->uuid), [
                'predecessor_id' => $b->uuid, 'successor_id' => $a->uuid, 'dep_type' => 'fs',
            ]);

        $response->assertSessionHasErrors('successor_id');
        $this->assertStringContainsString('Pour slab', session('errors')->first('successor_id'));
        $this->assertSame(1, TaskDependency::count());
    }

    public function test_bulk_status_is_applied_to_the_selection(): void
    {
        $tasks = app(TaskService::class);
        $a = $tasks->create($this->project, ['name' => 'A']);
        $b = $tasks->create($this->project, ['name' => 'B']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.bulk', $this->project->uuid), [
                'uuids' => [$a->uuid, $b->uuid], 'status' => 'blocked',
            ])
            ->assertRedirect();

        $this->assertSame('blocked', $a->fresh()->status);
        $this->assertSame('blocked', $b->fresh()->status);
    }

    public function test_a_bulk_edit_with_nothing_to_change_says_so(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'A']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.bulk', $this->project->uuid), ['uuids' => [$task->uuid]])
            ->assertSessionHasErrors('status');
    }

    // ── Authorisation ────────────────────────────────────────────────────────

    public function test_a_field_worker_cannot_add_or_delete_tasks(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.tasks.store', $this->project->uuid), ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->actingAs($this->fieldWorker)
            ->delete(route('project-management.tasks.destroy', [$this->project->uuid, $task->uuid]))
            ->assertForbidden();
    }

    public function test_linking_tasks_needs_the_schedule_grant(): void
    {
        $tasks = app(TaskService::class);
        $a = $tasks->create($this->project, ['name' => 'A']);
        $b = $tasks->create($this->project, ['name' => 'B']);

        // The manager holds pm.schedule.manage; the outsider holds nothing.
        $this->actingAs($this->manager)
            ->post(route('project-management.dependencies.store', $this->project->uuid), [
                'predecessor_id' => $a->uuid, 'successor_id' => $b->uuid, 'dep_type' => 'fs',
            ])
            ->assertRedirect();

        $this->actingAs($this->outsider)
            ->post(route('project-management.dependencies.store', $this->project->uuid), [
                'predecessor_id' => $a->uuid, 'successor_id' => $b->uuid, 'dep_type' => 'ss',
            ])
            ->assertForbidden();
    }

    public function test_another_tenants_project_is_not_reachable(): void
    {
        $alpha = \App\Modules\Tenancy\Models\Company::create(['name' => 'Alpha Construction']);
        $bravo = \App\Modules\Tenancy\Models\Company::create(['name' => 'Bravo Civils']);
        $this->entitleCompany($alpha);

        $theirs = $this->asCompany($bravo, fn () => $this->makeProject(['code' => 'PRJ-B']));

        // The manager acts inside Alpha; the URL carries Bravo's id. 404, never 403 — a 403 would
        // confirm the project exists (PM-1010).
        $this->manager->forceFill(['company_id' => $alpha->id])->save();

        $this->actingAs($this->manager->fresh())
            ->get(route('project-management.work', $theirs->uuid))
            ->assertNotFound();
    }
}
