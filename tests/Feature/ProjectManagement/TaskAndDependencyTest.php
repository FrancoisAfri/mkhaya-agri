<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskDependency;
use App\Modules\Operations\ProjectManagement\Services\DependencyService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use InvalidArgumentException;

/**
 * Tasks and their precedence links (PM-1120, PM-1123).
 */
class TaskAndDependencyTest extends ProjectManagementTestCase
{
    private TaskService $tasks;

    private DependencyService $links;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tasks = app(TaskService::class);
        $this->links = app(DependencyService::class);
        $this->project = $this->makeProject();
    }

    // ── Tasks ────────────────────────────────────────────────────────────────

    public function test_tasks_are_numbered_and_inherit_the_project_currency(): void
    {
        $first = $this->tasks->create($this->project, ['name' => 'Excavate']);
        $second = $this->tasks->create($this->project, ['name' => 'Blind']);

        $this->assertSame('T-0001', $first->code);
        $this->assertSame('T-0002', $second->code);
        $this->assertSame($this->project->currency, $first->currency);
    }

    public function test_subtasks_nest_to_any_depth_and_inherit_their_parents_wbs_place(): void
    {
        $parent = $this->tasks->create($this->project, ['name' => 'Superstructure']);
        $child = $this->tasks->create($this->project, ['name' => 'Level 1'], $parent);
        $grandchild = $this->tasks->create($this->project, ['name' => 'Columns'], $child);

        $this->assertSame($parent->id, $child->parent_task_id);
        $this->assertSame($child->id, $grandchild->parent_task_id);
        $this->assertSame($parent->wbs_node_id, $child->wbs_node_id);
    }

    public function test_a_parent_aggregates_dates_and_weighted_progress_from_its_children(): void
    {
        $parent = $this->tasks->create($this->project, ['name' => 'Slab']);

        // Two hours at 100% and two weeks at 0% is not "half done".
        $this->tasks->create($this->project, [
            'name' => 'Quick check', 'duration_minutes' => 120,
            'planned_start' => now()->addDays(2), 'planned_end' => now()->addDays(2)->addHours(2),
        ], $parent);
        $this->tasks->create($this->project, [
            'name' => 'Pour and cure', 'duration_minutes' => 20160,
            'planned_start' => now(), 'planned_end' => now()->addDays(14),
        ], $parent);

        Task::where('name', 'Quick check')->first()->update(['progress_pct' => 100]);
        $this->tasks->rollUp($parent->fresh());

        $parent = $parent->fresh();

        $this->assertLessThan(2, (float) $parent->progress_pct, 'Progress must be weighted by duration, not averaged.');
        $this->assertSame('derived', $parent->progress_source);
        // Dates span the children: earliest start, latest finish.
        $this->assertSame(now()->toDateString(), $parent->planned_start->toDateString());
        $this->assertSame(now()->addDays(14)->toDateString(), $parent->planned_end->toDateString());
    }

    public function test_a_parent_is_only_complete_once_every_child_is(): void
    {
        $parent = $this->tasks->create($this->project, ['name' => 'Slab']);
        $a = $this->tasks->create($this->project, ['name' => 'Pour'], $parent);
        $this->tasks->create($this->project, ['name' => 'Cure'], $parent);

        $this->tasks->update($a, ['actual_end' => now()]);

        $this->assertNull($parent->fresh()->actual_end, 'A parent cannot finish before its work does.');
    }

    public function test_the_scheduling_engines_output_cannot_be_set_by_a_caller(): void
    {
        $task = $this->tasks->create($this->project, ['name' => 'Excavate']);

        $this->tasks->update($task, [
            'name' => 'Excavate to level', 'is_critical' => true, 'total_float_minutes' => -999,
        ]);

        $task = $task->fresh();

        $this->assertSame('Excavate to level', $task->name);
        // Otherwise a form could fabricate a critical path (PM-ADR-005).
        $this->assertFalse($task->is_critical);
        $this->assertNull($task->total_float_minutes);
    }

    public function test_a_status_change_emits_the_right_evidence(): void
    {
        $task = $this->tasks->create($this->project, ['name' => 'Excavate']);

        $this->tasks->update($task, ['status' => 'in_progress']);
        $this->tasks->update($task->fresh(), ['status' => 'done']);

        $types = EvidenceEvent::where('subject_type', 'task')->pluck('event_type')->all();

        $this->assertEqualsCanonicalizing(['task.started', 'task.completed'], $types);
    }

    public function test_reopening_a_finished_task_is_recorded_as_such(): void
    {
        $task = $this->tasks->create($this->project, ['name' => 'Excavate']);
        $this->tasks->update($task, ['status' => 'done']);
        $this->tasks->update($task->fresh(), ['status' => 'in_progress']);

        // Its earlier completion no longer stands, and the ledger has to say so.
        $this->assertTrue(EvidenceEvent::where('event_type', 'task.reopened')->exists());
    }

    public function test_bulk_edit_is_capped(): void
    {
        $uuids = array_fill(0, TaskService::BULK_LIMIT + 1, 'x');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limited to 200 tasks');

        $this->tasks->bulkUpdate($uuids, ['status' => 'blocked']);
    }

    public function test_bulk_edit_changes_every_selected_task(): void
    {
        $a = $this->tasks->create($this->project, ['name' => 'A']);
        $b = $this->tasks->create($this->project, ['name' => 'B']);

        $changed = $this->tasks->bulkUpdate([$a->uuid, $b->uuid], ['status' => 'blocked']);

        $this->assertSame(2, $changed);
        $this->assertSame('blocked', $a->fresh()->status);
        $this->assertSame('blocked', $b->fresh()->status);
    }

    public function test_deleting_a_task_takes_its_subtasks_and_its_links(): void
    {
        $parent = $this->tasks->create($this->project, ['name' => 'Slab']);
        $child = $this->tasks->create($this->project, ['name' => 'Pour'], $parent);
        $other = $this->tasks->create($this->project, ['name' => 'Strike formwork']);
        $link = $this->links->link($child, $other);

        $this->tasks->delete($parent);

        $this->assertSoftDeleted('pm_tasks', ['id' => $parent->id]);
        $this->assertSoftDeleted('pm_tasks', ['id' => $child->id]);
        // A link to a task nobody can see would constrain the schedule invisibly.
        $this->assertSoftDeleted('pm_task_dependencies', ['id' => $link->id]);
    }

    // ── Dependencies ─────────────────────────────────────────────────────────

    public function test_all_four_link_types_are_accepted_with_lag_and_lead(): void
    {
        foreach (['fs', 'ss', 'ff', 'sf'] as $index => $type) {
            $a = $this->tasks->create($this->project, ['name' => "A{$index}"]);
            $b = $this->tasks->create($this->project, ['name' => "B{$index}"]);

            $link = $this->links->link($a, $b, $type, $index % 2 === 0 ? 480 : -480);

            $this->assertSame($type, $link->dep_type);
        }

        $this->assertSame(4, TaskDependency::count());
        $this->assertSame(-480, TaskDependency::where('dep_type', 'ss')->first()->lag_minutes, 'A negative lag is a lead.');
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $task = $this->tasks->create($this->project, ['name' => 'Excavate']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'Excavate' cannot depend on itself");

        $this->links->link($task, $task);
    }

    public function test_a_loop_is_rejected_and_the_path_is_named(): void
    {
        $pour = $this->tasks->create($this->project, ['name' => 'Pour slab']);
        $cure = $this->tasks->create($this->project, ['name' => 'Cure slab']);
        $strike = $this->tasks->create($this->project, ['name' => 'Strike formwork']);

        $this->links->link($pour, $cure);
        $this->links->link($cure, $strike);

        try {
            // Closing the loop: strike → pour, when pour already reaches strike.
            $this->links->link($strike, $pour);
            $this->fail('A cycle should be rejected.');
        } catch (InvalidArgumentException $e) {
            // "circular dependency detected" tells a site engineer nothing they can act on.
            $this->assertStringContainsString('Pour slab', $e->getMessage());
            $this->assertStringContainsString('Cure slab', $e->getMessage());
            $this->assertStringContainsString('Strike formwork', $e->getMessage());
            $this->assertStringContainsString('→', $e->getMessage());
        }

        $this->assertSame(2, TaskDependency::count(), 'The bad link must not be stored.');
    }

    public function test_a_long_chain_is_validated_without_a_query_per_hop(): void
    {
        $chain = [];
        for ($i = 0; $i < 30; $i++) {
            $chain[] = $this->tasks->create($this->project, ['name' => "Step {$i}"]);
        }
        for ($i = 0; $i < 29; $i++) {
            $this->links->link($chain[$i], $chain[$i + 1]);
        }

        \DB::enableQueryLog();
        try {
            $this->links->link($chain[29], $chain[0]);
            $this->fail('Closing a 30-step chain is still a cycle.');
        } catch (InvalidArgumentException) {
            // expected
        }
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // The edges load once and the walk happens in memory; a per-hop query would make a long
        // chain unusably slow to validate.
        $this->assertLessThanOrEqual(3, $queries, "Cycle detection used {$queries} queries.");
    }

    public function test_tasks_in_different_projects_cannot_be_linked(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $a = $this->tasks->create($this->project, ['name' => 'Ours']);
        $b = $this->tasks->create($other, ['name' => 'Theirs']);

        $this->expectException(InvalidArgumentException::class);

        $this->links->link($a, $b);
    }

    public function test_an_unknown_link_type_is_refused(): void
    {
        $a = $this->tasks->create($this->project, ['name' => 'A']);
        $b = $this->tasks->create($this->project, ['name' => 'B']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use fs, ss, ff or sf');

        $this->links->link($a, $b, 'xx');
    }

    public function test_the_graph_is_handed_to_the_engine_as_plain_arrays(): void
    {
        $a = $this->tasks->create($this->project, ['name' => 'A', 'duration_minutes' => 480]);
        $b = $this->tasks->create($this->project, ['name' => 'B', 'duration_minutes' => 240]);
        $this->links->link($a, $b, 'fs', 60);

        $graph = $this->links->graph($this->project);

        // No models: the scheduling engine touches no database (PM-ADR-005).
        $this->assertIsArray($graph['tasks'][$a->id]);
        $this->assertSame(480, $graph['tasks'][$a->id]['duration_minutes']);
        $this->assertSame(
            ['predecessor_id' => $a->id, 'successor_id' => $b->id, 'type' => 'fs', 'lag_minutes' => 60],
            $graph['links'][0]
        );
    }
}
