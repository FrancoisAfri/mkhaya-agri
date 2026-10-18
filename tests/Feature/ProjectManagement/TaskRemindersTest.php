<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Notifications\TasksDueNotification;
use App\Modules\Operations\ProjectManagement\Services\AssignmentService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Telling people what they owe today, and what they already owed.
 *
 * `pm:alerts` warns whoever runs a project that deadlines are approaching. Nothing said anything to
 * the person actually holding the task, so a due date was something you found out about by opening
 * the board and looking — which is precisely what somebody who has forgotten a task does not do.
 */
class TaskRemindersTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Everything is built inside a tenant, because the command sweeps tenant by tenant — it has
         * to, since CompanyScope is inert in the console and an unset RLS variable matches every
         * row. Untenanted fixtures are invisible to it, exactly as untenanted production data
         * would be.
         */
        $this->company = Company::create(['name' => 'Reminder Co']);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->entitleCompany($this->company);

        $this->project = $this->asCompany($this->company, fn () => $this->makeProject());

        Notification::fake();
    }

    /** As a request would: inside the tenant that owns the data. */
    private function inTenant(callable $callback): mixed
    {
        return $this->asCompany($this->company, $callback);
    }

    private function holder(): array
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'h' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1,
            'company_id' => $this->company->id,
        ]);

        $member = $this->inTenant(fn () => ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Holder',
            'role_key' => 'team_member', 'is_active' => true, 'user_id' => $user->id,
        ]));

        return [$user, $member];
    }

    private function task(string $name, ?string $due, ?ProjectMember $to = null, array $attributes = []): Task
    {
        return $this->inTenant(function () use ($name, $due, $to, $attributes) {
            $task = app(TaskService::class)->create($this->project, array_merge([
                'name' => $name, 'planned_end' => $due,
            ], $attributes));

            if ($to) {
                app(AssignmentService::class)->assign($task, $to);
            }

            return $task;
        });
    }

    private function remind(): void
    {
        $this->artisan('pm:task-reminders', ['--company' => $this->company->id])
            ->assertExitCode(0);
    }

    public function test_the_person_holding_a_task_hears_about_it_on_the_day(): void
    {
        [$user, $member] = $this->holder();
        $this->task('Due today', now()->toDateString(), $member);

        $this->remind();

        Notification::assertSentTo($user, TasksDueNotification::class, function ($n) {
            return count($n->dueToday) === 1 && $n->overdue === [];
        });
    }

    public function test_something_already_late_is_chased_with_how_late_it_is(): void
    {
        // "3 days" and "3 months" are different problems, and a reminder that will not say which
        // is one people stop reading.
        [$user, $member] = $this->holder();
        $this->task('Late', now()->subDays(9)->toDateString(), $member);

        $this->remind();

        Notification::assertSentTo($user, TasksDueNotification::class, function ($n) {
            return count($n->overdue) === 1 && $n->overdue[0]['days'] === 9;
        });
    }

    public function test_one_message_carries_everything_that_person_holds(): void
    {
        /*
         * Somebody who has let a dozen things slip is exactly the person who would otherwise get a
         * dozen emails — and a dozen emails is how a mail rule gets written, after which the
         * thirteenth is filed unread with the rest.
         */
        [$user, $member] = $this->holder();

        $this->task('One', now()->toDateString(), $member);
        $this->task('Two', now()->toDateString(), $member);
        $this->task('Three', now()->subDay()->toDateString(), $member);

        $this->remind();

        Notification::assertSentToTimes($user, TasksDueNotification::class, 1);
        Notification::assertSentTo($user, TasksDueNotification::class, fn ($n) =>
            count($n->dueToday) === 2 && count($n->overdue) === 1);
    }

    public function test_work_that_is_finished_or_shelved_is_left_alone(): void
    {
        /*
         * Chasing somebody about a task that was completed, cancelled or deliberately archived is
         * how a reminder loses its authority — and archived work is off the board on purpose.
         */
        [$user, $member] = $this->holder();

        foreach (['done', 'cancelled', Task::STATUS_ARCHIVED] as $status) {
            $task = $this->task("Finished {$status}", now()->subDay()->toDateString(), $member);
            $task->forceFill(['status' => $status])->save();
        }

        $this->remind();

        Notification::assertNotSentTo($user, TasksDueNotification::class);
    }

    public function test_a_task_with_no_date_is_never_chased(): void
    {
        // There is nothing to be late for.
        [$user, $member] = $this->holder();
        $this->task('Undated', null, $member);

        $this->remind();

        Notification::assertNotSentTo($user, TasksDueNotification::class);
    }

    public function test_a_task_not_yet_due_waits_its_turn(): void
    {
        [$user, $member] = $this->holder();
        $this->task('Next week', now()->addWeek()->toDateString(), $member);

        $this->remind();

        Notification::assertNotSentTo($user, TasksDueNotification::class);
    }

    public function test_an_unassigned_task_is_chased_to_whoever_raised_it(): void
    {
        /*
         * An overdue task nobody owns is still somebody's problem — usually the person who wrote it
         * down — and saying nothing about it is how it stays unassigned.
         */
        $this->actingAs($this->manager);

        $this->task('Nobody owns this', now()->subDays(2)->toDateString(), null, [
            'created_by' => $this->manager->id,
        ]);

        $this->remind();

        Notification::assertSentTo($this->manager, TasksDueNotification::class);
    }

    public function test_a_dry_run_reports_without_sending(): void
    {
        [$user, $member] = $this->holder();
        $this->task('Due today', now()->toDateString(), $member);

        $this->artisan('pm:task-reminders', ['--company' => $this->company->id, '--dry' => true])
            ->assertExitCode(0);

        Notification::assertNotSentTo($user, TasksDueNotification::class);
    }
}
