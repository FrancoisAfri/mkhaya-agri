<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskStatus;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TaskStatusService;

/**
 * A project naming its own board columns (T-0008).
 *
 * Every project had the same five: not started, in progress, blocked, done, cancelled. Real work
 * does not divide that way — a construction job wants "awaiting materials", a design team wants "in
 * review" — and a board that cannot say those things gets worked around in the task title.
 *
 * The whole risk of this feature is that a task's status is not a label. It drives the evidence
 * ledger, progress derivation, what counts as open work, claims and the scheduler. A project whose
 * columns were free-form would emit no completion events and roll up no progress, and nothing would
 * say so. So a step carries a *category* drawn from the built-in five, the board draws the step, and
 * everything downstream keeps reading the category. Most of what follows guards that seam.
 */
class TaskStepsTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function steps(): TaskStatusService
    {
        return app(TaskStatusService::class);
    }

    /** A snagging board: named columns, each meaning one of the built-in states. */
    private function snaggingBoard(): void
    {
        $this->steps()->replace($this->project, [
            ['label' => 'Raised',              'category' => 'not_started', 'is_default' => true],
            ['label' => 'Awaiting materials',  'category' => 'blocked'],
            ['label' => 'On site',             'category' => 'in_progress'],
            ['label' => 'Ready for inspection', 'category' => 'in_progress'],
            ['label' => 'Signed off',          'category' => 'done'],
        ]);
    }

    private function props(array $query = []): array
    {
        return $this->get(route('project-management.board', [$this->project->uuid] + $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ── The default is invisible until somebody wants it ─────────────────────

    public function test_a_project_that_has_named_nothing_gets_the_built_in_five(): void
    {
        /*
         * The feature has to be invisible until it is wanted. Materialising five rows per project
         * would put an identical set of columns in the database for every job anybody ever created,
         * purely so the default could look like a choice.
         */
        $keys = array_column($this->steps()->stepsFor($this->project), 'key');

        $this->assertSame(Task::BOARD_STATUSES, $keys);
        $this->assertSame(0, TaskStatus::where('project_id', $this->project->id)->count());
    }

    public function test_the_board_draws_the_projects_own_columns(): void
    {
        $this->snaggingBoard();

        $labels = array_column($this->actingAs($this->manager)->props()['steps'], 'label');

        $this->assertSame(
            ['Raised', 'Awaiting materials', 'On site', 'Ready for inspection', 'Signed off'],
            $labels,
        );
    }

    // ── The seam: a name on the board, a meaning underneath ──────────────────

    public function test_a_custom_column_keeps_the_meaning_it_was_given(): void
    {
        /*
         * The point of the whole design. "Awaiting materials" is a name; `blocked` is what the
         * evidence ledger, the progress engine and the claim pack see.
         */
        $this->snaggingBoard();

        $task = app(TaskService::class)->create($this->project, ['name' => 'Cracked lintel']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => 'awaiting_materials'])
            ->assertRedirect();

        $task->refresh();

        $this->assertSame('blocked', $task->status, 'The meaning is what the rest of the module reads.');
        $this->assertSame('awaiting_materials', $task->status_key, 'The key is only which column it sits in.');
    }

    public function test_two_columns_may_share_one_meaning_without_merging(): void
    {
        /*
         * "On site" and "Ready for inspection" are both in progress. Matching cards on meaning
         * rather than on column would draw the same task in both, which is the bug this guards.
         */
        $this->snaggingBoard();

        $onSite = app(TaskService::class)->create($this->project, ['name' => 'Patch the wall']);
        $ready  = app(TaskService::class)->create($this->project, ['name' => 'Repaint the frame']);

        $this->steps()->resolve($this->project, 'on_site');

        app(TaskService::class)->update($onSite, $this->steps()->resolve($this->project, 'on_site'));
        app(TaskService::class)->update($ready, $this->steps()->resolve($this->project, 'ready_for_inspection'));

        $cards = collect($this->actingAs($this->manager)->props()['tasks']);

        $this->assertSame('on_site', $cards->firstWhere('id', $onSite->uuid)['column']);
        $this->assertSame('ready_for_inspection', $cards->firstWhere('id', $ready->uuid)['column']);
        $this->assertSame('in_progress', $onSite->fresh()->status);
        $this->assertSame('in_progress', $ready->fresh()->status);
    }

    public function test_finishing_in_a_custom_column_is_still_a_completion(): void
    {
        /*
         * The failure mode worth being loudest about: a board whose "done" column was merely a name
         * would look finished and record nothing — no completion event, no progress, no claim.
         */
        $this->snaggingBoard();

        $task = app(TaskService::class)->create($this->project, ['name' => 'Replace the pane']);

        app(TaskService::class)->update($task, $this->steps()->resolve($this->project, 'signed_off'));

        $this->assertSame('done', $task->fresh()->status);
        $this->assertFalse(
            Task::where('id', $task->id)->open()->exists(),
            'Work signed off in a custom column is not still open work.',
        );
    }

    // ── New work lands somewhere the team actually looks ─────────────────────

    public function test_a_new_task_lands_in_the_projects_first_column(): void
    {
        // A snagging board starts at "Raised", not "Not started". A task appearing in a column the
        // team does not use is a task nobody sees.
        $this->snaggingBoard();

        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), ['name' => 'New snag'])
            ->assertRedirect();

        $task = Task::where('project_id', $this->project->id)->where('name', 'New snag')->firstOrFail();

        $this->assertSame('raised', $task->status_key);
        $this->assertSame('not_started', $task->status);
    }

    public function test_a_built_in_column_stores_no_key(): void
    {
        /*
         * There is no row to point at for a built-in step. Storing its name in `status_key` would
         * strand the task the moment somebody did define columns, because nothing would match it.
         */
        $resolved = $this->steps()->resolve($this->project, 'in_progress');

        $this->assertSame(['status' => 'in_progress', 'status_key' => null], $resolved);
    }

    // ── Everything that was already talking to this module keeps working ─────

    public function test_a_plain_status_from_an_api_client_is_still_accepted(): void
    {
        /*
         * The field app, the API and every existing integration send built-in statuses and have no
         * idea this feature exists. Refusing them because the project has since named its own
         * columns would break clients silently and remotely.
         */
        $this->snaggingBoard();

        $resolved = $this->steps()->resolve($this->project, 'done');

        $this->assertSame('done', $resolved['status']);
        $this->assertNull($resolved['status_key']);
    }

    public function test_a_column_this_project_does_not_have_is_refused_by_name(): void
    {
        $this->snaggingBoard();

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [
                $this->project->uuid,
                app(TaskService::class)->create($this->project, ['name' => 'X'])->uuid,
            ]), ['status' => 'somebody_elses_column'])
            ->assertSessionHasErrors('status');
    }

    // ── Editing the set ──────────────────────────────────────────────────────

    public function test_renaming_a_column_does_not_strand_the_tasks_in_it(): void
    {
        /*
         * Tasks reference the key. Regenerating it from the label on every save would empty the
         * column each time somebody fixed a typo in its name — and the tasks would still be there,
         * invisible, which is the worst kind of missing work.
         */
        $this->snaggingBoard();

        $task = app(TaskService::class)->create($this->project, ['name' => 'Cracked lintel']);
        app(TaskService::class)->update($task, $this->steps()->resolve($this->project, 'on_site'));

        $existing = $this->steps()->own($this->project);

        $this->steps()->replace($this->project, $existing->map(fn (TaskStatus $s) => [
            'id'         => $s->uuid,
            'label'      => $s->key === 'on_site' ? 'With the contractor' : $s->label,
            'category'   => $s->category,
            'is_default' => $s->is_default,
        ])->all());

        $this->assertSame('on_site', $task->fresh()->status_key, 'The key survives a rename.');
        $this->assertSame(
            'With the contractor',
            TaskStatus::where('project_id', $this->project->id)->where('key', 'on_site')->value('label'),
        );
    }

    public function test_removing_a_column_moves_its_tasks_rather_than_losing_them(): void
    {
        /*
         * Deleting a column out from under live tasks would make them vanish from the board while
         * remaining perfectly real in the database. Nothing would report it.
         */
        $this->snaggingBoard();

        $task = app(TaskService::class)->create($this->project, ['name' => 'Cracked lintel']);
        app(TaskService::class)->update($task, $this->steps()->resolve($this->project, 'awaiting_materials'));

        $this->steps()->replace($this->project, $this->steps()->own($this->project)
            ->reject(fn (TaskStatus $s) => $s->key === 'awaiting_materials')
            ->map(fn (TaskStatus $s) => [
                'id' => $s->uuid, 'label' => $s->label, 'category' => $s->category,
                'is_default' => $s->is_default,
            ])->values()->all());

        $task->refresh();

        $this->assertSame('raised', $task->status_key, 'It moves to the column new work starts in.');
        $this->assertSame('not_started', $task->status, 'And its meaning follows the column it is now in.');
    }

    public function test_a_board_must_have_somewhere_to_finish(): void
    {
        /*
         * Without a done column there is nowhere to drag a card to complete it, and completion is
         * what drives claims, progress and the ledger. A project could be worked for months and
         * record nothing.
         */
        $this->expectException(\InvalidArgumentException::class);

        $this->steps()->replace($this->project, [
            ['label' => 'Raised', 'category' => 'not_started', 'is_default' => true],
            ['label' => 'On site', 'category' => 'in_progress'],
        ]);
    }

    public function test_a_column_has_to_mean_one_of_the_built_in_states(): void
    {
        // A free-form meaning is exactly what would silently switch off completions and progress.
        $this->expectException(\InvalidArgumentException::class);

        $this->steps()->replace($this->project, [
            ['label' => 'Blue', 'category' => 'chartreuse', 'is_default' => true],
            ['label' => 'Green', 'category' => 'done'],
        ]);
    }

    public function test_exactly_one_column_takes_new_work(): void
    {
        // Two defaults would make where a new task lands a matter of insertion order; none would
        // make it arbitrary. The database refuses two, so the service has to guarantee one.
        $this->steps()->replace($this->project, [
            ['label' => 'Briefed', 'category' => 'not_started', 'is_default' => true],
            ['label' => 'In review', 'category' => 'in_progress', 'is_default' => true],
            ['label' => 'Shipped', 'category' => 'done'],
        ]);

        $this->assertSame(
            1,
            TaskStatus::where('project_id', $this->project->id)->where('is_default', true)->count(),
        );
    }

    public function test_a_board_nobody_could_read_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->steps()->replace($this->project, array_map(
            fn (int $i) => ['label' => "Step {$i}", 'category' => $i === 1 ? 'done' : 'in_progress'],
            range(1, TaskStatusService::MAX_STEPS + 1),
        ));
    }

    public function test_the_editor_is_told_what_a_removal_would_move(): void
    {
        // Discovering afterwards that a column you deleted had eleven tasks in it is discovering it
        // too late.
        $this->snaggingBoard();

        $task = app(TaskService::class)->create($this->project, ['name' => 'Cracked lintel']);
        app(TaskService::class)->update($task, $this->steps()->resolve($this->project, 'on_site'));

        $this->assertSame(1, $this->actingAs($this->manager)->props()['stepCounts']['on_site'] ?? 0);
    }

    public function test_only_somebody_who_runs_the_project_may_change_its_columns(): void
    {
        // Changing the columns changes the board for everybody on the project — including what
        // "done" means for the claims raised off it — so it is the project-level grant, not the
        // one that merely lets somebody record work.
        $this->actingAs($this->fieldWorker)
            ->put(route('project-management.board.steps', $this->project->uuid), [
                'steps' => [['label' => 'Mine', 'category' => 'done', 'is_default' => true]],
            ])
            ->assertForbidden();
    }

    public function test_archived_work_stays_off_a_custom_board_too(): void
    {
        // Archiving is orthogonal to the columns: it takes a task off the board whatever the board
        // happens to be called.
        $this->snaggingBoard();

        $task = app(TaskService::class)->create($this->project, ['name' => 'Superseded']);
        $task->forceFill(['status' => Task::STATUS_ARCHIVED, 'status_key' => null])->save();

        $this->assertNotContains(
            $task->uuid,
            array_column($this->actingAs($this->manager)->props()['tasks'], 'id'),
        );
    }
}
