<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\WbsService;

/**
 * The board, calendar and timeline (PM-1250…1256).
 *
 * They share one payload, and that is the thing worth proving: three views that fetched separately
 * would eventually disagree, and the person who noticed would be right to stop trusting all three.
 */
class BoardPageTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['planned_start' => '2026-03-02']);
    }

    private function props(): array
    {
        return $this->get(route('project-management.board', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_the_payload_carries_what_all_three_views_need(): void
    {
        $node = app(WbsService::class)->create($this->project, ['name' => 'Substructure']);
        $task = app(TaskService::class)->create($this->project, [
            'name' => 'Excavate', 'planned_start' => '2026-03-02', 'planned_end' => '2026-03-06',
        ], null, $node);

        $card = $this->actingAs($this->manager)->props()['tasks'][0];

        $this->assertSame($task->uuid, $card['id']);
        $this->assertSame('not_started', $card['status']);       // the board column
        $this->assertSame('2026-03-02', $card['start']);          // the calendar and the bar
        $this->assertSame('2026-03-06', $card['end']);
        $this->assertSame('1 Substructure', $card['group_label']); // the grouping filter
    }

    // ── Adding work from the views themselves ────────────────────────────────

    /**
     * A task typed into a column arrives in that column.
     *
     * The point of adding from the board is that the click carries the status; if the task landed in
     * the default column the user would have to drag every one they created, which is the work the
     * feature exists to remove.
     */
    public function test_a_task_added_from_a_column_takes_that_columns_status(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name'   => 'Order rebar',
                'status' => 'blocked',
            ])
            ->assertRedirect();

        $task = Task::where('project_id', $this->project->id)->where('name', 'Order rebar')->firstOrFail();

        $this->assertSame('blocked', $task->status);
    }

    /**
     * A task added on a calendar day is dated to that day, both ends.
     *
     * Dating only the start would leave it undated for the calendar's own purposes — a card with no
     * end does not render on the grid, so the task would vanish from the very view it was created in.
     */
    public function test_a_task_added_on_a_calendar_day_is_dated_to_that_day(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name'          => 'Site induction',
                'planned_start' => '2026-03-11',
                'planned_end'   => '2026-03-11',
            ])
            ->assertRedirect();

        $task = Task::where('project_id', $this->project->id)->where('name', 'Site induction')->firstOrFail();

        $this->assertSame('2026-03-11', $task->planned_start->toDateString());
        $this->assertSame('2026-03-11', $task->planned_end->toDateString());

        // And it reaches the calendar: the grid draws a card only when both ends are present.
        $card = collect($this->actingAs($this->manager)->props()['tasks'])
            ->firstWhere('name', 'Site induction');

        $this->assertSame('2026-03-11', $card['start']);
        $this->assertSame('2026-03-11', $card['end']);
    }

    /** Added while filtered to a work package, it belongs to that package. */
    public function test_a_task_added_under_a_breakdown_filter_lands_in_that_group(): void
    {
        $node = app(WbsService::class)->create($this->project, ['name' => 'Substructure']);

        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name'        => 'Pour blinding',
                'status'      => 'not_started',
                'wbs_node_id' => $node->uuid,
            ])
            ->assertRedirect();

        $card = collect($this->actingAs($this->manager)->props()['tasks'])
            ->firstWhere('name', 'Pour blinding');

        $this->assertSame($node->uuid, $card['group']);
    }

    /** An empty name is refused rather than creating a nameless card. */
    public function test_a_task_cannot_be_added_without_a_name(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Task::where('project_id', $this->project->id)->count());
    }

    /** The board says whether the add box should be offered at all. */
    public function test_the_board_reports_whether_the_viewer_may_create_tasks(): void
    {
        $props = $this->actingAs($this->manager)->props();

        $this->assertArrayHasKey('createTasks', $props['can']);
        $this->assertTrue($props['can']['createTasks']);
    }

    // ── Assigning work ───────────────────────────────────────────────────────

    /**
     * One person, many tasks, one request.
     *
     * Assigning was previously impossible from any screen — the endpoint existed and nothing called
     * it — and doing it one dialog at a time is the reason a foreman with twenty tasks never got
     * them recorded.
     */
    public function test_many_tasks_can_be_assigned_to_one_person_at_once(): void
    {
        $member = \App\Modules\Operations\ProjectManagement\Models\ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Sipho Ndlovu',
            'role_key' => 'foreman', 'is_active' => true,
        ]);

        $tasks = collect(['Excavate', 'Blind', 'Reinforce'])
            ->map(fn ($name) => app(TaskService::class)->create($this->project, ['name' => $name]));

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.bulk', $this->project->uuid), [
                'uuids'     => $tasks->pluck('uuid')->all(),
                'member_id' => $member->uuid,
            ])
            ->assertRedirect();

        foreach ($tasks as $task) {
            $this->assertDatabaseHas('pm_task_assignments', [
                'task_id'   => $task->id,
                'member_id' => $member->id,
            ]);
        }
    }

    /** Status and assignee travel together — it is one gesture, not two round trips. */
    public function test_a_bulk_change_can_set_status_and_assignee_in_one_request(): void
    {
        $member = \App\Modules\Operations\ProjectManagement\Models\ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Thandi M',
            'role_key' => 'engineer', 'is_active' => true,
        ]);

        $task = app(TaskService::class)->create($this->project, ['name' => 'Survey']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.bulk', $this->project->uuid), [
                'uuids' => [$task->uuid], 'status' => 'in_progress', 'member_id' => $member->uuid,
            ])
            ->assertRedirect();

        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertDatabaseHas('pm_task_assignments', ['task_id' => $task->id, 'member_id' => $member->id]);
    }

    /** A bulk request that would change nothing is refused rather than reporting success. */
    public function test_a_bulk_change_with_nothing_to_apply_is_refused(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Idle']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.bulk', $this->project->uuid), ['uuids' => [$task->uuid]])
            ->assertSessionHasErrors('status');
    }

    public function test_a_card_can_be_dragged_to_another_column(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]), [
                'status' => 'in_progress',
            ])
            ->assertRedirect();

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    /**
     * Moving a card is a status change like any other, so it leaves the same evidence. A board that
     * quietly bypassed the ledger would make the timeline disagree with what people did.
     */
    public function test_moving_a_card_records_evidence_like_any_other_status_change(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]), [
                'status' => 'in_progress',
            ]);

        // subject_id is the internal bigint; subject_uuid is the public id the event carries.
        $this->assertDatabaseHas('pm_evidence_events', [
            'event_type'   => 'task.started',
            'subject_uuid' => $task->uuid,
        ]);
    }

    public function test_an_invented_status_is_refused(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]), [
                'status' => 'nearly_done',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('not_started', $task->fresh()->status);
    }

    public function test_a_recurrence_template_never_appears_as_a_card(): void
    {
        $template = app(TaskService::class)->create($this->project, ['name' => 'Weekly inspection']);
        $template->forceFill(['is_recurrence_template' => true])->save();

        app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $names = array_column($this->actingAs($this->manager)->props()['tasks'], 'name');

        $this->assertSame(['Excavate'], $names);
    }

    public function test_a_field_worker_can_look_but_not_move(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.board', $this->project->uuid))
            ->assertOk();

        $this->actingAs($this->fieldWorker)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]), [
                'status' => 'done',
            ])
            ->assertForbidden();

        $this->assertSame('not_started', $task->fresh()->status);
    }

    public function test_a_task_from_another_project_cannot_be_moved_through_this_board(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $theirs = app(TaskService::class)->create($other, ['name' => 'Their work']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $theirs->uuid]), [
                'status' => 'done',
            ])
            ->assertNotFound();

        $this->assertSame('not_started', $theirs->fresh()->status);
    }

    public function test_the_board_and_the_schedule_agree_on_what_is_critical(): void
    {
        $a = app(TaskService::class)->create($this->project, ['name' => 'Excavate', 'duration_minutes' => 5 * 9 * 60]);
        $b = app(TaskService::class)->create($this->project, ['name' => 'Signage', 'duration_minutes' => 60]);
        app(\App\Modules\Operations\ProjectManagement\Services\SchedulingService::class)->reschedule($this->project);

        $cards = collect($this->actingAs($this->manager)->props()['tasks'])->keyBy('name');

        $this->assertSame((bool) $a->fresh()->is_critical, $cards['Excavate']['is_critical']);
        $this->assertSame((bool) $b->fresh()->is_critical, $cards['Signage']['is_critical']);
    }

    // ── Opening a card ───────────────────────────────────────────────────────

    /**
     * The board can open a task.
     *
     * Until this it could not: a card carried a name, a date and a drag handle, and there was no
     * way to read what anyone had said about the work or attach anything to it. The drawer already
     * existed on the work list — the board simply never sent it the payload, so every conversation
     * about a task on the board happened somewhere the project could not see.
     */
    public function test_a_card_can_be_opened_into_the_drawer(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Pour slab']);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.board', [$this->project->uuid, 'task' => $task->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($task->uuid, $props['taskDetail']['id']);
        $this->assertArrayHasKey('comments', $props['taskDetail']);
        $this->assertArrayHasKey('attachments', $props['taskDetail']);
    }

    public function test_the_board_sends_nothing_when_no_task_is_asked_for(): void
    {
        // The drawer's payload is several queries; paying for them on every board load, for the
        // usual case where nobody has opened anything, would be a tax on the whole page.
        $this->assertNull($this->actingAs($this->manager)->props()['taskDetail']);
    }

    public function test_a_task_from_another_project_cannot_be_opened_through_this_board(): void
    {
        $other = $this->makeProject();
        $theirs = app(TaskService::class)->create($other, ['name' => 'Someone elses work']);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.board', [$this->project->uuid, 'task' => $theirs->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['taskDetail'], 'The lookup is scoped to the project that was authorised.');
    }

    public function test_the_board_offers_the_team_so_work_can_be_given_to_somebody(): void
    {
        $this->assertIsArray($this->actingAs($this->manager)->props()['members']);
    }

    // ── Archiving ────────────────────────────────────────────────────────────

    /**
     * Archived work is kept and not drawn.
     *
     * A project accumulates tasks that are neither done nor cancelled — superseded, deferred, raised
     * twice. Leaving them in a column buries the work that is live; deleting them loses the reason
     * they existed, which is the thing somebody asks about months later.
     */
    public function test_an_archived_task_is_off_the_board(): void
    {
        $live = app(TaskService::class)->create($this->project, ['name' => 'Live work']);
        $shelved = app(TaskService::class)->create($this->project, ['name' => 'Superseded']);
        $shelved->forceFill(['status' => Task::STATUS_ARCHIVED])->save();

        $props = $this->actingAs($this->manager)->props();
        $ids = array_column($props['tasks'], 'id');

        $this->assertContains($live->uuid, $ids);
        $this->assertNotContains($shelved->uuid, $ids);
        $this->assertSame(1, $props['archivedCount'], 'The count is what makes the toggle worth drawing.');
        $this->assertFalse($props['showArchived']);
    }

    public function test_archived_work_comes_back_when_it_is_asked_for(): void
    {
        $shelved = app(TaskService::class)->create($this->project, ['name' => 'Superseded']);
        $shelved->forceFill(['status' => Task::STATUS_ARCHIVED])->save();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.board', [$this->project->uuid, 'archived' => 1]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertContains($shelved->uuid, array_column($props['tasks'], 'id'));
        $this->assertTrue($props['showArchived']);
    }

    public function test_the_board_draws_no_archived_column(): void
    {
        /*
         * The whole point of archiving. A column for it would put the shelved work back on the
         * board beside the live work, which is the situation it exists to fix.
         */
        $this->assertNotContains(
            Task::STATUS_ARCHIVED,
            $this->actingAs($this->manager)->props()['statuses'],
        );
    }

    public function test_a_card_can_be_archived_and_restored(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Superseded']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => Task::STATUS_ARCHIVED])
            ->assertRedirect();

        $this->assertSame(Task::STATUS_ARCHIVED, $task->fresh()->status);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => 'not_started'])
            ->assertRedirect();

        $this->assertSame('not_started', $task->fresh()->status);
    }

    public function test_archived_work_is_not_counted_as_open(): void
    {
        // `open` feeds progress rollups and the "what is left" figures. Archived work is not left
        // to do, so counting it would make every project look permanently behind.
        $task = app(TaskService::class)->create($this->project, ['name' => 'Superseded']);
        $task->forceFill(['status' => Task::STATUS_ARCHIVED])->save();

        $this->assertFalse(
            Task::where('project_id', $this->project->id)->open()->where('id', $task->id)->exists(),
        );
    }

    // ── Raising a task with everything it needs ──────────────────────────────

    private function member(string $name = 'Sipho Ndlovu'): \App\Modules\Operations\ProjectManagement\Models\ProjectMember
    {
        return \App\Modules\Operations\ProjectManagement\Models\ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => $name,
            'role_key' => 'foreman', 'is_active' => true,
        ]);
    }

    /**
     * A task can be raised with somebody already on it.
     *
     * Both endpoints existed and neither could be reached until the task did, so putting a name to
     * work meant creating it, finding it again, opening it and assigning it. Four steps, and the
     * honest outcome was a board full of tasks nobody owned.
     */
    public function test_a_task_can_be_created_with_somebody_already_on_it(): void
    {
        $member = $this->member();

        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name' => 'Strip formwork', 'member_id' => $member->uuid,
            ])
            ->assertRedirect();

        $task = Task::where('project_id', $this->project->id)->where('name', 'Strip formwork')->firstOrFail();

        $this->assertDatabaseHas('pm_task_assignments', ['task_id' => $task->id, 'member_id' => $member->id]);
    }

    /**
     * A refused assignment does not take the task down with it.
     *
     * Somebody on leave, or without the ticket the work needs, is a reason to pick another name —
     * not a reason to make the person type the task again.
     */
    public function test_a_task_survives_an_assignment_that_is_refused(): void
    {
        $inactive = \App\Modules\Operations\ProjectManagement\Models\ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Left the site',
            'role_key' => 'foreman', 'is_active' => false,
        ]);

        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name' => 'Chase the steel', 'member_id' => $inactive->uuid,
            ])
            ->assertSessionHasErrors('member_id');

        $this->assertDatabaseHas('pm_tasks', [
            'project_id' => $this->project->id, 'name' => 'Chase the steel',
        ]);
    }

    /**
     * A task can be raised with the photograph of the thing it is about.
     *
     * The attachment endpoint needed a task uuid, so the file could only be added after the fact —
     * which in practice meant the photo stayed on the phone.
     */
    public function test_a_task_can_be_created_with_a_file_already_on_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pm') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>");

        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), [
                'name' => 'Repair the crack',
                'attachments' => [new \Illuminate\Http\UploadedFile($path, 'crack.pdf', 'application/pdf', null, true)],
            ])
            ->assertRedirect();

        $task = Task::where('project_id', $this->project->id)->where('name', 'Repair the crack')->firstOrFail();

        $this->assertDatabaseHas('pm_task_attachments', ['task_id' => $task->id, 'filename' => 'crack.pdf']);

        @unlink($path);
    }

    /**
     * The companions are creation-only.
     *
     * An edit that quietly accepted an assignee and discarded it would be worse than one that never
     * offered the field — the screen would report success and nothing would have happened.
     */
    public function test_an_edit_does_not_take_an_assignee(): void
    {
        $member = $this->member('Thandi M');
        $task = app(TaskService::class)->create($this->project, ['name' => 'Survey']);

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.update', [$this->project->uuid, $task->uuid]), [
                'name' => 'Survey', 'member_id' => $member->uuid,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('pm_task_assignments', ['task_id' => $task->id, 'member_id' => $member->id]);
    }

    public function test_a_task_still_needs_nothing_but_a_name(): void
    {
        /*
         * The reason the composer exists. A capture box that demands an owner and a date before it
         * will accept "chase the steel delivery" is a box people stop using.
         */
        $this->actingAs($this->manager)
            ->post(route('project-management.tasks.store', $this->project->uuid), ['name' => 'Chase the steel'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    // ── Who a task belongs to ────────────────────────────────────────────────

    /**
     * Every screen that lists tasks says who they are for.
     *
     * Assignment was recorded and shown nowhere. A card carried a code, a name and a date, so work
     * with a foreman on it looked exactly like work nobody had picked up — which is the first
     * question anybody asks of a board, and no screen in the module could answer it.
     */
    public function test_a_card_says_who_it_belongs_to(): void
    {
        $member = $this->member('Sipho Ndlovu');
        $task = app(TaskService::class)->create($this->project, ['name' => 'Strip formwork']);

        app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class)
            ->assign($task, $member);

        $card = collect($this->actingAs($this->manager)->props()['tasks'])->firstWhere('id', $task->uuid);

        $this->assertSame('Sipho Ndlovu', $card['assignees'][0]['name']);
        $this->assertSame($member->uuid, $card['assignees'][0]['id']);
    }

    public function test_an_unassigned_card_carries_an_empty_list_rather_than_null(): void
    {
        // So every screen can iterate without first asking whether there is anything to iterate,
        // and so "nobody" is a state the page can draw rather than a hole in the payload.
        $task = app(TaskService::class)->create($this->project, ['name' => 'Nobody has this']);

        $card = collect($this->actingAs($this->manager)->props()['tasks'])->firstWhere('id', $task->uuid);

        $this->assertSame([], $card['assignees']);
    }

    public function test_the_lead_is_named_first(): void
    {
        /*
         * A task with four people on it has one who is answerable for it. Ordered by insertion, the
         * front of the stack would be whoever happened to be added first — and that is the name
         * everybody then reads as the owner.
         */
        $task = app(TaskService::class)->create($this->project, ['name' => 'Pour slab']);
        $assignments = app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class);

        $assignments->assign($task, $this->member('First Added'));
        $assignments->assign($task, $this->member('The Lead'), ['is_lead' => true]);

        $card = collect($this->actingAs($this->manager)->props()['tasks'])->firstWhere('id', $task->uuid);

        $this->assertSame('The Lead', $card['assignees'][0]['name']);
        $this->assertTrue($card['assignees'][0]['is_lead']);
    }

    public function test_the_whole_board_costs_one_query_for_its_assignees(): void
    {
        /*
         * A relation loaded per card is how twenty tasks become forty queries and a real programme
         * stops rendering. Asserted rather than assumed, because the cost only shows up on somebody
         * else's data.
         */
        $assignments = app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class);

        foreach (range(1, 6) as $n) {
            $assignments->assign(
                app(TaskService::class)->create($this->project, ['name' => "Task {$n}"]),
                $this->member("Person {$n}"),
            );
        }

        $presenter = app(\App\Modules\Operations\ProjectManagement\Support\Presenters\TaskAssigneePresenter::class);
        $ids = Task::where('project_id', $this->project->id)->pluck('id');

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $presenter->forTasks($ids);

        // Two: the assignments, and the eager-loaded members.
        $this->assertLessThanOrEqual(2, count(\Illuminate\Support\Facades\DB::getQueryLog()));

        \Illuminate\Support\Facades\DB::disableQueryLog();
    }

    public function test_an_assignment_whose_member_is_gone_is_left_out(): void
    {
        // A membership deleted out from under an assignment leaves a row pointing at nobody.
        // Drawing that as a blank face is worse than drawing nothing at all.
        $member = $this->member('About to leave');
        $task = app(TaskService::class)->create($this->project, ['name' => 'Orphaned']);

        app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class)->assign($task, $member);

        \Illuminate\Support\Facades\DB::table('pm_project_members')->where('id', $member->id)->delete();

        $card = collect($this->actingAs($this->manager)->props()['tasks'])->firstWhere('id', $task->uuid);

        $this->assertSame([], $card['assignees']);
    }

    // ── Telling somebody their task was finished ─────────────────────────────

    /**
     * Moving a card into Done tells whoever it belonged to.
     *
     * Anybody who may edit the project can drag a card into Done, so the person answerable for that
     * work found out by happening to look at the board — which is to say, sometimes not at all.
     */
    public function test_the_assignee_hears_that_their_task_was_completed(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $member = $this->memberWithLogin();
        $task = app(TaskService::class)->create($this->project, ['name' => 'Strip formwork']);

        app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class)
            ->assign($task, $member);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => 'done'])
            ->assertRedirect();

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->userOf($member),
            \App\Modules\Operations\ProjectManagement\Notifications\TaskCompletedNotification::class,
        );
    }

    public function test_nobody_is_told_about_what_they_just_did_themselves(): void
    {
        /*
         * Telling somebody what they have this second done is noise, and noise is what makes the
         * rest of the channel ignorable.
         */
        \Illuminate\Support\Facades\Notification::fake();

        $member = $this->memberWithLogin($this->manager);
        $task = app(TaskService::class)->create($this->project, ['name' => 'My own task']);

        app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class)
            ->assign($task, $member);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => 'done']);

        // Named rather than `assertNothingSent`: putting somebody on a task is itself worth an
        // email now, and the setup above does exactly that. What must not arrive is the completion.
        \Illuminate\Support\Facades\Notification::assertNotSentTo(
            $this->userOf($member),
            \App\Modules\Operations\ProjectManagement\Notifications\TaskCompletedNotification::class,
        );
    }

    public function test_re_saving_a_task_that_is_already_done_says_nothing(): void
    {
        // Only a real transition counts. A status write that changes nothing must not announce a
        // completion that happened last week.
        $member = $this->memberWithLogin();
        $task = app(TaskService::class)->create($this->project, ['name' => 'Long finished']);

        app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class)
            ->assign($task, $member);

        app(TaskService::class)->update($task, ['status' => 'done']);

        \Illuminate\Support\Facades\Notification::fake();

        app(TaskService::class)->update($task->fresh(), ['status' => 'done']);

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_moving_a_card_to_any_other_column_says_nothing(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $member = $this->memberWithLogin();
        $task = app(TaskService::class)->create($this->project, ['name' => 'Still going']);

        app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class)
            ->assign($task, $member);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => 'blocked']);

        // Only completion is announced. Blocked is a state somebody watching the board can see.
        \Illuminate\Support\Facades\Notification::assertNotSentTo(
            $this->userOf($member),
            \App\Modules\Operations\ProjectManagement\Notifications\TaskCompletedNotification::class,
        );
    }

    public function test_a_bulk_completion_still_tells_each_owner(): void
    {
        /*
         * `bulkUpdate` wraps its own transaction around the loop, so a notice announced from inside
         * the single-task path would have been sent from inside somebody else's transaction — and a
         * mail sent from a transaction that later rolls back cannot be unsent.
         */
        \Illuminate\Support\Facades\Notification::fake();

        $member = $this->memberWithLogin();
        $assignments = app(\App\Modules\Operations\ProjectManagement\Services\AssignmentService::class);

        $tasks = collect(['One', 'Two'])
            ->map(fn ($name) => app(TaskService::class)->create($this->project, ['name' => $name]));

        foreach ($tasks as $task) {
            $assignments->assign($task, $member);
        }

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.bulk', $this->project->uuid), [
                'uuids' => $tasks->pluck('uuid')->all(), 'status' => 'done',
            ])
            ->assertRedirect();

        \Illuminate\Support\Facades\Notification::assertSentToTimes(
            $this->userOf($member),
            \App\Modules\Operations\ProjectManagement\Notifications\TaskCompletedNotification::class,
            2,
        );
    }

    public function test_an_unassigned_task_being_completed_notifies_whoever_raised_it(): void
    {
        // Somebody raised it, so somebody cares that it is finished. Without this, completing
        // unassigned work would be silent — and on a young project most work is unassigned.
        \Illuminate\Support\Facades\Notification::fake();

        $raiser = $this->userOf($this->memberWithLogin());

        $task = app(TaskService::class)->create($this->project, [
            'name' => 'Nobody owns this', 'created_by' => $raiser->id,
        ]);

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]),
                ['status' => 'done']);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $raiser,
            \App\Modules\Operations\ProjectManagement\Notifications\TaskCompletedNotification::class,
        );
    }

    /** The login behind a member. There is no `user` relation on ProjectMember; only the column. */
    private function userOf(\App\Modules\Operations\ProjectManagement\Models\ProjectMember $member): \App\Core\User\Models\User
    {
        return \App\Core\User\Models\User::findOrFail($member->user_id);
    }

    /** A project member backed by a real login, since a notification needs somebody to send to. */
    private function memberWithLogin(?\App\Core\User\Models\User $user = null): \App\Modules\Operations\ProjectManagement\Models\ProjectMember
    {
        $user ??= \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'email' => 'pm+' . \Illuminate\Support\Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => $this->project->company_id,
        ]);

        return \App\Modules\Operations\ProjectManagement\Models\ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Owner ' . \Illuminate\Support\Str::random(4),
            'role_key' => 'foreman', 'is_active' => true, 'user_id' => $user->id,
        ]);
    }
}
