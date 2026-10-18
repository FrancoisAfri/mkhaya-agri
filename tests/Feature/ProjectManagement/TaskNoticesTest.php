<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Notifications\TaskAssignedNotification;
use App\Modules\Operations\ProjectManagement\Notifications\TaskMentionNotification;
use App\Modules\Operations\ProjectManagement\Services\AssignmentService;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Telling people the things they would otherwise find out too late.
 *
 * Work was handed over in silence — an assignment appeared on a board somebody had no reason to
 * open that morning, and the first they heard of it was being asked why it had not started. And
 * being tagged in a comment subscribed a person to the task, so they heard about everything that
 * happened *next* and never about the thing that named them.
 */
class TaskNoticesTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function member(?User $user = null, string $name = 'Sipho Ndlovu'): ProjectMember
    {
        $user ??= User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'pm+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->project->company_id,
        ]);

        return ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => $name,
            'role_key' => 'foreman', 'is_active' => true, 'user_id' => $user->id,
        ]);
    }

    private function userOf(ProjectMember $member): User
    {
        return User::findOrFail($member->user_id);
    }

    private function task(string $name = 'Strip formwork'): Task
    {
        return app(TaskService::class)->create($this->project, ['name' => $name]);
    }

    // ── Being given work ─────────────────────────────────────────────────────

    public function test_being_put_on_a_task_is_worth_hearing_about(): void
    {
        Notification::fake();

        $member = $this->member();

        $this->actingAs($this->manager);
        app(AssignmentService::class)->assign($this->task(), $member);

        Notification::assertSentTo($this->userOf($member), TaskAssignedNotification::class);
    }

    public function test_putting_a_task_on_yourself_says_nothing(): void
    {
        // Being emailed about what you have this second done yourself is noise, and the noise is
        // what makes the rest of the channel ignorable.
        Notification::fake();

        $member = $this->member($this->manager);

        $this->actingAs($this->manager);
        app(AssignmentService::class)->assign($this->task(), $member);

        Notification::assertNothingSent();
    }

    public function test_changing_an_existing_assignment_is_not_a_new_one(): void
    {
        /*
         * Re-saving somebody's allocation, or making them the lead, is a change to work they
         * already know they have. Announcing it again teaches people to ignore the message.
         */
        $member = $this->member();
        $task = $this->task();

        $this->actingAs($this->manager);
        app(AssignmentService::class)->assign($task, $member);

        Notification::fake();

        app(AssignmentService::class)->assign($task, $member, ['allocation_pct' => 50, 'is_lead' => true]);

        Notification::assertNothingSent();
    }

    public function test_somebody_with_no_login_is_simply_not_notified(): void
    {
        // A subcontractor's foreman may be on the project without an account. That is not a
        // failure, and it must not stop the assignment.
        Notification::fake();

        $member = ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'No login',
            'role_key' => 'foreman', 'is_active' => true,
        ]);

        $this->actingAs($this->manager);
        $assignment = app(AssignmentService::class)->assign($this->task(), $member);

        $this->assertNotNull($assignment->id, 'The assignment still has to be made.');
        Notification::assertNothingSent();
    }

    // ── Being named in a comment ─────────────────────────────────────────────

    public function test_being_tagged_in_a_comment_reaches_the_person_tagged(): void
    {
        Notification::fake();

        $member = $this->member();
        $task = $this->task();

        $this->actingAs($this->manager);
        app(CollaborationService::class)->comment($task, 'Can you confirm the rebar spec?', [$member->user_id]);

        Notification::assertSentTo($this->userOf($member), TaskMentionNotification::class);
    }

    public function test_tagging_yourself_says_nothing(): void
    {
        Notification::fake();

        $this->actingAs($this->manager);
        app(CollaborationService::class)->comment($this->task(), 'Note to self', [$this->manager->id]);

        Notification::assertNothingSent();
    }

    public function test_a_comment_naming_nobody_notifies_nobody(): void
    {
        Notification::fake();

        $this->actingAs($this->manager);
        app(CollaborationService::class)->comment($this->task(), 'Just a note.');

        Notification::assertNothingSent();
    }

    public function test_a_tag_still_subscribes_the_person_to_the_task(): void
    {
        // The behaviour that already existed has to survive the notification being added.
        $member = $this->member();
        $task = $this->task();

        $this->actingAs($this->manager);
        app(CollaborationService::class)->comment($task, 'Over to you.', [$member->user_id]);

        $this->assertDatabaseHas('pm_task_watchers', [
            'task_id' => $task->id, 'user_id' => $member->user_id, 'source' => 'mention',
        ]);
    }

    public function test_only_people_with_a_login_are_offered_for_tagging(): void
    {
        /*
         * A mention is a user, not a membership. Offering somebody with no account would produce a
         * tag that reaches nobody, which looks identical to one that was ignored.
         */
        $withLogin = $this->member(name: 'Has an account');

        ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'No account',
            'role_key' => 'foreman', 'is_active' => true,
        ]);

        $task = $this->task();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.board', [$this->project->uuid, 'task' => $task->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $names = array_column($props['taskDetail']['taggable'], 'name');

        $this->assertContains('Has an account', $names);
        $this->assertNotContains('No account', $names);
        $this->assertSame([$withLogin->user_id], array_column($props['taskDetail']['taggable'], 'id'));
    }

    // ── Correcting a task where it is read ───────────────────────────────────

    public function test_a_task_can_be_reworded_and_dated_from_the_drawer(): void
    {
        /*
         * The drawer showed a task's conversation, its files and who was on it, and offered no way
         * to fix the wording or the due date. The only route was to delete and retype, which throws
         * away the comments and files hanging off it.
         */
        $task = $this->task('Typo in teh name');

        $this->actingAs($this->manager)
            ->patch(route('project-management.tasks.update', [$this->project->uuid, $task->uuid]), [
                'name'          => 'Strip the formwork',
                'description'   => 'Once the slab has cured.',
                'planned_end'   => '2026-10-15',
                'priority'      => 'high',
            ])
            ->assertRedirect();

        $task->refresh();

        $this->assertSame('Strip the formwork', $task->name);
        $this->assertSame('Once the slab has cured.', $task->description);
        $this->assertSame('2026-10-15', $task->planned_end?->toDateString());
        $this->assertSame('high', $task->priority);
    }

    public function test_the_drawer_carries_the_fields_it_lets_you_edit(): void
    {
        // A form cannot show what it was not given, and an edit box that opens empty silently
        // wipes whatever was there when it is saved.
        $task = app(TaskService::class)->create($this->project, [
            'name' => 'Dated work', 'description' => 'Why it matters',
            'planned_end' => '2026-10-15', 'priority' => 'high',
        ]);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.board', [$this->project->uuid, 'task' => $task->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Why it matters', $props['taskDetail']['description']);
        $this->assertSame('2026-10-15', $props['taskDetail']['planned_end']);
        $this->assertSame('high', $props['taskDetail']['priority']);
    }
}
