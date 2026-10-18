<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Support\Str;

/**
 * My Work: one board of everything assigned to a person, across every project.
 *
 * The screen reported "No work assigned to you yet" to people holding a list of assigned tasks. It
 * rendered entirely from IndexedDB, so a browser that had never completed a sync and a person with
 * genuinely nothing to do produced the same page — and the page chose the reassuring reading.
 *
 * These tests are about the payload, which is what makes the difference: the board now arrives with
 * the page, so an empty board means an empty workload and nothing else.
 */
class MyWorkBoardTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function member(Project $project, $user): ProjectMember
    {
        return ProjectMember::create([
            'uuid'       => (string) Str::uuid(),
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'role_key'   => 'contributor',
        ]);
    }

    private function assign(Task $task, ProjectMember $member): TaskAssignment
    {
        return TaskAssignment::create([
            'uuid'        => (string) Str::uuid(),
            'project_id'  => $task->project_id,
            'task_id'     => $task->id,
            'member_id'   => $member->id,
            'assigned_at' => now(),
        ]);
    }

    private function task(array $attributes = [], ?Project $project = null): Task
    {
        return app(TaskService::class)->create($project ?? $this->project, array_merge([
            'name'          => 'Excavate',
            'planned_start' => '2026-03-02',
            'planned_end'   => '2026-03-06',
        ], $attributes));
    }

    private function props(?int $viewUser = null): array
    {
        $url = route('project-management.field') . ($viewUser ? "?user={$viewUser}" : '');

        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    // ── The bug ──────────────────────────────────────────────────────────────

    public function test_a_task_assigned_to_me_is_on_my_board(): void
    {
        $task = $this->task();
        $this->assign($task, $this->member($this->project, $this->manager));

        $props = $this->actingAs($this->manager)->props();

        $cards = $props['tasks']['not_started'];
        $this->assertCount(1, $cards, 'An assigned task did not reach the board.');
        $this->assertSame($task->uuid, $cards[0]['id']);
        $this->assertSame('Excavate', $cards[0]['name']);
    }

    /** Every write goes back through the project's own route, so the card has to say which project. */
    public function test_each_card_carries_the_project_it_belongs_to(): void
    {
        $task = $this->task();
        $this->assign($task, $this->member($this->project, $this->manager));

        $card = $this->actingAs($this->manager)->props()['tasks']['not_started'][0];

        $this->assertSame($this->project->uuid, $card['project']['id']);
        $this->assertSame($this->project->code, $card['project']['code']);
    }

    public function test_work_spans_every_project_the_person_is_on(): void
    {
        $second = $this->makeProject(['name' => 'Second job']);

        $one = $this->task(['name' => 'Excavate']);
        $two = $this->task(['name' => 'Pour slab'], $second);

        $this->assign($one, $this->member($this->project, $this->manager));
        $this->assign($two, $this->member($second, $this->manager));

        $names = collect($this->actingAs($this->manager)->props()['tasks']['not_started'])
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Excavate', 'Pour slab'], $names,
            'My Work showed one project rather than everything assigned to the person.');
    }

    public function test_a_task_assigned_to_somebody_else_is_not_on_my_board(): void
    {
        $task = $this->task();
        $this->assign($task, $this->member($this->project, $this->fieldWorker));

        $props = $this->actingAs($this->manager)->props();

        $this->assertCount(0, $props['tasks']['not_started'],
            "Another person's task appeared on this board.");
    }

    // ── The columns ──────────────────────────────────────────────────────────

    public function test_cards_arrive_in_the_column_matching_their_status(): void
    {
        $member = $this->member($this->project, $this->manager);

        foreach (['not_started', 'in_progress', 'blocked', 'done'] as $status) {
            $task = $this->task(['name' => "Task {$status}"]);
            $task->forceFill(['status' => $status])->save();
            $this->assign($task, $member);
        }

        $props = $this->actingAs($this->manager)->props();

        foreach (['not_started', 'in_progress', 'blocked', 'done'] as $status) {
            $this->assertCount(1, $props['tasks'][$status], "Nothing landed in the {$status} column.");
            $this->assertSame("Task {$status}", $props['tasks'][$status][0]['name']);
        }
    }

    /**
     * Cancelled and archived are outcomes, not places work sits. Grouping server-side is what keeps
     * them off the board — a client-side groupBy drops an unrecognised status into the first column.
     */
    public function test_cancelled_and_archived_work_is_not_drawn_on_the_board(): void
    {
        $member = $this->member($this->project, $this->manager);

        foreach (['cancelled', 'archived'] as $status) {
            $task = $this->task(['name' => "Task {$status}"]);
            $task->forceFill(['status' => $status])->save();
            $this->assign($task, $member);
        }

        $props = $this->actingAs($this->manager)->props();

        $total = collect($props['columns'])->sum(fn ($key) => count($props['tasks'][$key]));
        $this->assertSame(0, $total, 'Work that is finished with appeared on the board.');
    }

    public function test_the_columns_are_sent_as_keys_for_the_browser_to_translate(): void
    {
        $props = $this->actingAs($this->manager)->props();

        // Keys, not labels: translating here bakes one locale into the payload.
        $this->assertSame(['not_started', 'in_progress', 'blocked', 'done'], $props['columns']);
    }

    // ── Looking at somebody else's board ─────────────────────────────────────

    public function test_a_manager_can_open_the_board_of_somebody_on_their_project(): void
    {
        $task = $this->task(['name' => 'Their task']);
        $this->assign($task, $this->member($this->project, $this->fieldWorker));

        $props = $this->actingAs($this->manager)->props($this->fieldWorker->id);

        $this->assertSame($this->fieldWorker->id, $props['subject']);
        $this->assertSame('Their task', $props['tasks']['not_started'][0]['name']);
    }

    /**
     * A query parameter is not authorisation. Asking for a board you may not see gives you your
     * own rather than an error, because the alternative is a screen that confirms who exists.
     */
    public function test_asking_for_a_board_you_may_not_see_returns_your_own(): void
    {
        // On no project, so on nobody's list of people to look at.
        $stranger = $this->makeUser('pm-stranger@example.test', null);

        $task = $this->task(['name' => 'My own task']);
        $this->assign($task, $this->member($this->project, $this->manager));

        $props = $this->actingAs($this->manager)->props($stranger->id);

        $this->assertSame($this->manager->id, $props['subject'],
            "A board was opened for somebody the viewer has no business seeing.");
        $this->assertSame('My own task', $props['tasks']['not_started'][0]['name']);
    }

    /** The page itself is gated: no right to see tasks, no board at all. */
    public function test_somebody_with_no_task_rights_cannot_open_the_page(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('project-management.field'))
            ->assertForbidden();
    }

    public function test_the_person_picker_only_offers_people_on_projects_you_can_see(): void
    {
        $this->member($this->project, $this->manager);
        $this->member($this->project, $this->fieldWorker);

        $ids = collect($this->actingAs($this->manager)->props()['people'])->pluck('id');

        $this->assertTrue($ids->contains($this->manager->id));
        $this->assertTrue($ids->contains($this->fieldWorker->id));
    }

    public function test_somebody_with_nobody_to_look_at_is_not_shown_a_picker_of_one(): void
    {
        // Nobody is a member of anything yet, so the only person on the list is the viewer.
        $props = $this->actingAs($this->manager)->props();

        $this->assertFalse($props['canPickPerson']);
        $this->assertSame([$this->manager->id], collect($props['people'])->pluck('id')->all());
    }

    // ── Moving a card ────────────────────────────────────────────────────────

    /**
     * The point of the whole exercise: the board writes through the project board's own route, so
     * "done here" and "done there" are the same fact rather than two that can disagree.
     */
    public function test_moving_a_card_to_done_moves_it_on_the_project_too(): void
    {
        $task = $this->task();
        $this->assign($task, $this->member($this->project, $this->manager));

        $this->actingAs($this->manager)
            ->patch(route('project-management.board.move', [$this->project->uuid, $task->uuid]), [
                'status' => 'done',
            ])
            ->assertRedirect();

        $this->assertSame('done', $task->fresh()->status);

        // And the card has moved column on My Work, because it is reading the same row.
        $props = $this->props();
        $this->assertCount(0, $props['tasks']['not_started']);
        $this->assertCount(1, $props['tasks']['done']);
    }
}
