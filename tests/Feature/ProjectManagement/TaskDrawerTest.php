<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskComment;
use App\Modules\Operations\ProjectManagement\Models\TaskRecurrence;
use App\Modules\Operations\ProjectManagement\Models\TaskWatcher;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The task drawer over HTTP (PM-1121, PM-1122).
 *
 * The services are covered directly; this is about the routes — that the drawer's payload is
 * complete, that a comment belongs to the person who wrote it, and that a task uuid from another
 * project is a 404 rather than an edit on someone else's job.
 */
class TaskDrawerTest extends ProjectManagementTestCase
{
    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        $this->task = app(TaskService::class)->create($this->project, ['name' => 'Erect the scaffold']);
    }

    private function detail(): array
    {
        return $this->get(route('project-management.work', [$this->project->uuid, 'task' => $this->task->uuid]))
            ->assertOk()
            ->viewData('page')['props']['taskDetail'];
    }

    // ── Who is doing the work ────────────────────────────────────────────────

    /**
     * The drawer says who the task belongs to.
     *
     * It could previously show a task's checklist, its conversation, its files and who was watching
     * it — and not who was doing it, which is the first question anybody asks about a task. Nothing
     * in the application assigned one either: the endpoint existed and no screen called it.
     */
    public function test_the_drawer_shows_who_is_assigned_and_who_could_be(): void
    {
        $member = ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Sipho Ndlovu',
            'role_key' => 'foreman', 'is_active' => true,
        ]);
        $spare = ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Thandi M',
            'role_key' => 'engineer', 'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $this->task->uuid]), [
                'member_id' => $member->uuid, 'allocation_pct' => 60, 'is_lead' => true,
            ])
            ->assertRedirect();

        $detail = $this->actingAs($this->manager)->detail();

        $this->assertCount(1, $detail['assignees']);
        $this->assertSame('Sipho Ndlovu', $detail['assignees'][0]['name']);
        $this->assertSame(60, $detail['assignees'][0]['allocation_pct']);
        $this->assertTrue($detail['assignees'][0]['is_lead']);

        // And the team, so the drawer can offer somebody rather than ask for a typed name.
        $this->assertContains($spare->uuid, array_column($detail['team'], 'id'));
    }

    public function test_somebody_can_be_taken_off_a_task(): void
    {
        $member = ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Sipho Ndlovu',
            'role_key' => 'foreman', 'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $this->task->uuid]), [
                'member_id' => $member->uuid,
            ])->assertRedirect();

        $this->assertCount(1, $this->actingAs($this->manager)->detail()['assignees']);

        $this->actingAs($this->manager)
            ->delete(route('project-management.assign.destroy', [
                $this->project->uuid, $this->task->uuid, $member->uuid,
            ]))
            ->assertRedirect();

        $this->assertSame([], $this->actingAs($this->manager)->detail()['assignees']);
    }

    /** Somebody on another project cannot be put on this task. */
    public function test_a_member_of_another_project_cannot_be_assigned(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $outsider = ProjectMember::create([
            'project_id' => $other->id, 'display_name' => 'Not Ours',
            'role_key' => 'foreman', 'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $this->task->uuid]), [
                'member_id' => $outsider->uuid,
            ])
            ->assertNotFound();

        $this->assertSame([], $this->actingAs($this->manager)->detail()['assignees']);
    }

    public function test_the_drawer_payload_carries_the_whole_conversation(): void
    {
        $this->actingAs($this->manager);

        $collab = app(CollaborationService::class);
        $item = $collab->addChecklistItem($this->task, 'Base plates level');
        $collab->setChecklistItemDone($item, true);
        $collab->comment($this->task, 'Ties are short.');

        $detail = $this->detail();

        $this->assertSame($this->task->code, $detail['code']);
        $this->assertSame('Base plates level', $detail['checklist'][0]['text']);
        $this->assertTrue($detail['checklist'][0]['is_done']);
        $this->assertSame('Ties are short.', $detail['comments'][0]['body']);
        $this->assertTrue($detail['comments'][0]['is_mine']);
        // Commenting subscribed the author, so the eye in the header is already lit.
        $this->assertTrue($detail['is_watching']);
    }

    public function test_the_page_carries_no_drawer_payload_until_a_task_is_asked_for(): void
    {
        $props = $this->actingAs($this->manager)
            ->get(route('project-management.work', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['taskDetail']);
    }

    public function test_a_recurrence_template_is_kept_out_of_the_task_list(): void
    {
        $this->actingAs($this->manager);

        $this->post(route('project-management.recurrence.store', [$this->project->uuid, $this->task->uuid]), [
            'freq' => 'WEEKLY', 'byday' => ['MO'], 'starts_at' => now()->addDay()->toDateString(),
        ])->assertRedirect();

        $props = $this->get(route('project-management.work', $this->project->uuid))
            ->assertOk()->viewData('page')['props'];

        $names = array_column($props['tasks'], 'code');

        $this->assertNotContains($this->task->code, $names, 'The template is a stencil, not work.');
        $this->assertNotEmpty($props['tasks'], 'Its occurrences are.');
        $this->assertTrue($props['tasks'][0]['is_occurrence']);
    }

    public function test_setting_a_recurrence_generates_straight_away(): void
    {
        $this->actingAs($this->manager);

        $this->post(route('project-management.recurrence.store', [$this->project->uuid, $this->task->uuid]), [
            'freq' => 'DAILY', 'count' => 3, 'starts_at' => now()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Not "wait until 02:30 and hope" — the person who set the rule sees it work.
        $this->assertSame(3, Task::where('occurrence_of_id', TaskRecurrence::first()->id)->count());
    }

    public function test_an_impossible_rule_comes_back_as_a_readable_error(): void
    {
        $this->actingAs($this->manager);

        $response = $this->post(route('project-management.recurrence.store', [$this->project->uuid, $this->task->uuid]), [
            'freq' => 'WEEKLY', 'interval' => 0, 'starts_at' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('interval');
        $this->assertSame(0, TaskRecurrence::count());
    }

    public function test_stopping_a_recurrence_cancels_only_the_untouched_future(): void
    {
        $this->actingAs($this->manager);

        $this->post(route('project-management.recurrence.store', [$this->project->uuid, $this->task->uuid]), [
            'freq' => 'DAILY', 'starts_at' => now()->addDay()->toDateString(), 'horizon_days' => 5,
        ]);

        $recurrence = TaskRecurrence::firstOrFail();

        $this->delete(route('project-management.recurrence.destroy', [$this->project->uuid, $recurrence->uuid]))
            ->assertRedirect();

        $this->assertFalse($recurrence->fresh()->is_active);
        $this->assertSame(0, Task::where('occurrence_of_id', $recurrence->id)->where('status', 'not_started')->count());
    }

    // ── Who may do what ──────────────────────────────────────────────────────

    public function test_a_comment_can_only_be_edited_by_the_person_who_wrote_it(): void
    {
        $this->actingAs($this->manager);
        $comment = app(CollaborationService::class)->comment($this->task, 'Ties are short.');

        // Even a manager cannot rewrite someone else's words and leave the byline on them.
        $this->actingAs($this->fieldWorker)
            ->patch(route('project-management.comments.update', [$this->project->uuid, $comment->uuid]), [
                'body' => 'Ties are fine.',
            ])
            ->assertForbidden();

        $this->assertSame('Ties are short.', $comment->fresh()->body);
    }

    public function test_the_person_running_the_work_can_remove_someone_elses_comment(): void
    {
        $this->actingAs($this->fieldWorker);
        $comment = app(CollaborationService::class)->comment($this->task, 'Wrong task, sorry.');

        $this->actingAs($this->manager)
            ->delete(route('project-management.comments.destroy', [$this->project->uuid, $comment->uuid]))
            ->assertRedirect();

        $this->assertSame(0, TaskComment::count());
    }

    public function test_watching_is_per_person_and_reversible(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.watch.store', [$this->project->uuid, $this->task->uuid]))
            ->assertRedirect();

        $this->assertSame([$this->manager->id], TaskWatcher::pluck('user_id')->map(fn ($i) => (int) $i)->all());

        $this->actingAs($this->manager)
            ->delete(route('project-management.watch.destroy', [$this->project->uuid, $this->task->uuid]))
            ->assertRedirect();

        $this->assertSame(0, TaskWatcher::count());
    }

    public function test_adding_a_checklist_item_needs_more_than_the_right_to_tick_one(): void
    {
        $item = app(CollaborationService::class)->addChecklistItem($this->task, 'Base plates level');

        // The field worker holds no pm.tasks.* grant at all in these fixtures.
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.checklist.store', [$this->project->uuid, $this->task->uuid]), [
                'text' => 'Something else',
            ])
            ->assertForbidden();

        $this->assertSame(1, ChecklistItem::count());
    }

    public function test_a_file_is_attached_and_streamed_back_under_the_caller_s_own_permission(): void
    {
        Storage::fake('local');
        $this->actingAs($this->manager);

        $this->post(route('project-management.attachments.store', [$this->project->uuid, $this->task->uuid]), [
            'file' => UploadedFile::fake()->createWithContent('cert.pdf', 'CERTIFICATE'),
        ])->assertRedirect();

        $detail = $this->detail();
        $this->assertSame('cert.pdf', $detail['attachments'][0]['filename']);

        // Streamed by this application rather than handed out as a link: every read passes the
        // policy check, and there is no URL in existence that would serve the file without one.
        $response = $this->get(route(
            'project-management.attachments.show',
            [$this->project->uuid, $detail['attachments'][0]['id']]
        ))->assertOk();

        $this->assertSame('CERTIFICATE', $response->streamedContent());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nothing may be written to a disk.');
    }

    /**
     * The wiring the drawer depends on: an action redirects back, and "back" is the page *with the
     * task still open*, so the reloaded payload is that task's and not null. Get this wrong and
     * every comment posts successfully and then empties the drawer.
     */
    public function test_an_action_returns_to_the_page_with_the_drawer_still_open(): void
    {
        $this->actingAs($this->manager);

        $workUrl = route('project-management.work', [$this->project->uuid, 'task' => $this->task->uuid]);

        $response = $this->from($workUrl)
            ->post(route('project-management.comments.store', [$this->project->uuid, $this->task->uuid]), [
                'body' => 'Ties are short.',
            ])
            ->assertRedirect($workUrl);

        $props = $this->followRedirects($response)->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['taskDetail']);
        $this->assertSame('Ties are short.', $props['taskDetail']['comments'][0]['body']);
    }

    public function test_a_task_from_another_project_is_not_reachable_through_this_one(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $theirTask = app(TaskService::class)->create($other, ['name' => 'Their work']);

        $this->actingAs($this->manager)
            ->post(route('project-management.comments.store', [$this->project->uuid, $theirTask->uuid]), [
                'body' => 'Wrong job.',
            ])
            ->assertNotFound();
    }
}
