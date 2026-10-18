<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Http\Controllers\PortalController;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Notifications\ClientRequestedTaskNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * What a client can do on their own project, and for how long.
 *
 * The portal let a client watch the work and rate the month. Every actual request went by email or
 * phone and landed nowhere the project could see, which is the one thing a client portal exists to
 * fix — so a client can now raise a request and watch what happened to it.
 *
 * The other half is when that stops. Access is for the duration of the work: once a project is
 * finished, an outside login keeps no reason to be in the system, and leaving it open means a
 * client from two years ago still holds a door into a live tenant.
 */
class ClientPortalRequestsTest extends ProjectManagementTestCase
{
    private Project $project;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['status' => 'active']);
        $this->client = $this->portalUser('client');
    }

    private function portalUser(string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'portal+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->project->company_id,
        ]);

        ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => ucfirst($role) . ' ' . Str::random(4),
            'role_key' => $role, 'is_active' => true, 'user_id' => $user->id,
        ]);

        return $user;
    }

    private function raise(User $as, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as)->post(
            route('project-management.portal.tasks.store', $this->project->uuid),
            $payload + ['name' => 'Please move the site office'],
        );
    }

    // ── Asking for something ─────────────────────────────────────────────────

    public function test_a_client_can_raise_a_request_on_their_project(): void
    {
        Notification::fake();

        $this->raise($this->client)->assertRedirect();

        $this->assertDatabaseHas('pm_tasks', [
            'project_id' => $this->project->id,
            'name'       => 'Please move the site office',
            'created_by' => $this->client->id,
        ]);
    }

    public function test_the_request_becomes_an_ordinary_task_on_the_board(): void
    {
        /*
         * The point of routing it through TaskService rather than inserting a row: the delivery
         * team schedules, assigns and answers it exactly as they do everything else, and it carries
         * a code so it can be referred to.
         */
        Notification::fake();

        $this->raise($this->client);

        $task = Task::where('project_id', $this->project->id)->latest('id')->firstOrFail();

        $this->assertNotNull($task->code);
        $this->assertSame('not_started', $task->status);
    }

    public function test_a_client_cannot_set_a_date_or_an_assignee(): void
    {
        /*
         * What a client asks for is not what the team has committed to. Letting an outside login
         * put a due date on the schedule would write a promise nobody made.
         */
        Notification::fake();

        $this->raise($this->client, ['planned_end' => '2026-01-01', 'status' => 'done']);

        $task = Task::where('project_id', $this->project->id)->latest('id')->firstOrFail();

        $this->assertNull($task->planned_end);
        $this->assertSame('not_started', $task->status);
    }

    public function test_the_project_team_is_told_about_it(): void
    {
        // A request on a board nobody refreshed is the email it replaced.
        Notification::fake();

        $manager = $this->portalUser('project_manager');

        $this->raise($this->client);

        Notification::assertSentTo($manager, ClientRequestedTaskNotification::class);
    }

    public function test_other_outside_logins_are_not_told(): void
    {
        // A contractor is not the person who answers a client's request, and a notice that reaches
        // people with no part in it is one they learn to filter.
        Notification::fake();

        $contractor = $this->portalUser('contractor');

        $this->raise($this->client);

        Notification::assertNotSentTo($contractor, ClientRequestedTaskNotification::class);
    }

    public function test_a_client_sees_their_own_requests_and_where_they_got_to(): void
    {
        Notification::fake();

        $this->raise($this->client);

        $props = $this->actingAs($this->client)
            ->get(route('project-management.portal'))
            ->assertOk()
            ->viewData('page')['props'];

        $requests = $props['projects'][0]['requests'];

        $this->assertSame('Please move the site office', $requests[0]['name']);
        $this->assertSame('not_started', $requests[0]['status']);
    }

    public function test_a_client_is_not_shown_the_teams_task_list(): void
    {
        /*
         * Their own requests only. The delivery team's tasks are not a client's business, and
         * showing them turns every internal note into something that has to be explained.
         */
        Notification::fake();

        app(\App\Modules\Operations\ProjectManagement\Services\TaskService::class)
            ->create($this->project, ['name' => 'Internal: chase the subbie']);

        $this->raise($this->client);

        $props = $this->actingAs($this->client)
            ->get(route('project-management.portal'))
            ->assertOk()
            ->viewData('page')['props'];

        $names = array_column($props['projects'][0]['requests'], 'name');

        $this->assertContains('Please move the site office', $names);
        $this->assertNotContains('Internal: chase the subbie', $names);
    }

    // ── Who may not ──────────────────────────────────────────────────────────

    public function test_a_contractor_cannot_raise_a_client_request(): void
    {
        $this->raise($this->portalUser('contractor'))->assertForbidden();
    }

    public function test_somebody_with_no_membership_cannot(): void
    {
        $outsider = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'nope' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->project->company_id,
        ]);

        $this->raise($outsider)->assertForbidden();
    }

    // ── When it ends ─────────────────────────────────────────────────────────

    public function test_a_finished_project_leaves_the_portal(): void
    {
        /*
         * The reason this matters: an outside login on a job that ended two years ago is a door
         * into a live tenant that nobody remembers is open.
         */
        $this->project->forceFill(['status' => 'completed'])->save();

        $props = $this->actingAs($this->client)
            ->get(route('project-management.portal'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['projects']);
    }

    public function test_a_finished_project_refuses_every_portal_action(): void
    {
        // Dropping it from the list is not enough on its own — the endpoints are still addressable
        // by anybody who kept the URL.
        $this->project->forceFill(['status' => 'completed'])->save();

        $this->raise($this->client)->assertForbidden();

        $this->actingAs($this->client)
            ->post(route('project-management.portal.rate', $this->project->uuid), ['score' => 5])
            ->assertForbidden();
    }

    public function test_work_that_is_merely_paused_still_opens(): void
    {
        // On hold is not finished. Cutting access off there would lock a client out of a job that
        // is coming back.
        $this->project->forceFill(['status' => 'on_hold'])->save();

        Notification::fake();

        $this->raise($this->client)->assertRedirect();
    }

    public function test_every_closing_status_ends_access(): void
    {
        foreach (['completed', 'cancelled', 'archived'] as $status) {
            $this->project->forceFill(['status' => $status])->save();

            $this->raise($this->client)->assertForbidden("status {$status} should end access");
        }

        // And stated the other way round, so adding a status to the enum without deciding which
        // side of the line it falls on shows up here rather than in production.
        $this->assertSame(['draft', 'active', 'on_hold'], PortalController::OPEN_STATUSES);
    }

    // ── A client on more than one project ────────────────────────────────────

    /**
     * The portal opens on the portfolio, not on whichever project sorted first.
     *
     * A client with one job wants that job. A client with five wants to know which of the five
     * needs them this week, and the old page could not answer that — every project after the first
     * was behind a dropdown, so five projects meant five deliberate selections to find the one
     * thing that was late.
     *
     * The dashboard is computed in the browser from this payload, so what has to hold here is that
     * the payload carries every project with the fields those charts read.
     */
    public function test_the_portal_carries_every_project_the_client_is_on(): void
    {
        $second = $this->makeProject(['status' => 'active', 'name' => 'Second job']);

        ProjectMember::create([
            'project_id' => $second->id, 'display_name' => 'Client on two',
            'role_key' => 'client', 'is_active' => true, 'user_id' => $this->client->id,
        ]);

        $projects = $this->actingAs($this->client)
            ->get(route('project-management.portal'))->assertOk()
            ->viewData('page')['props']['projects'];

        $this->assertCount(2, $projects, 'The portal showed one project to somebody on two.');

        // The fields the portfolio charts are drawn from. Without these the dashboard renders
        // empty bars and says a client is at 0% when nobody has measured them.
        foreach ($projects as $project) {
            foreach (['code', 'name', 'stage', 'progress', 'milestones', 'planned_end'] as $field) {
                $this->assertArrayHasKey($field, $project, "The payload has no {$field}.");
            }
        }
    }

    /** A milestone's date and whether it is done is what makes "late" computable at all. */
    public function test_each_project_carries_its_milestones_with_dates(): void
    {
        $task = app(\App\Modules\Operations\ProjectManagement\Services\TaskService::class)
            ->create($this->project, ['name' => 'Practical completion', 'planned_end' => '2026-03-06']);
        $task->forceFill(['is_milestone' => true])->save();

        $projects = $this->actingAs($this->client)
            ->get(route('project-management.portal'))->assertOk()
            ->viewData('page')['props']['projects'];

        $milestones = collect($projects)->firstWhere('code', $this->project->code)['milestones'];
        $milestone = collect($milestones)->firstWhere('name', 'Practical completion');

        $this->assertNotNull($milestone, 'A milestone did not reach the portal.');
        $this->assertSame('2026-03-06', $milestone['due']);
        $this->assertFalse($milestone['done']);
    }

    /** A client must not see another client's projects on their own dashboard. */
    public function test_the_portfolio_holds_only_this_persons_projects(): void
    {
        $other = $this->makeProject(['status' => 'active', 'name' => 'Somebody else']);
        $this->portalUserOn($other, 'client');

        $projects = $this->actingAs($this->client)
            ->get(route('project-management.portal'))->assertOk()
            ->viewData('page')['props']['projects'];

        $this->assertNotContains('Somebody else', array_column($projects, 'name'),
            "Another client's project appeared on this client's dashboard.");
    }

    private function portalUserOn(Project $project, string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'portal+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $project->company_id,
        ]);

        ProjectMember::create([
            'project_id' => $project->id, 'display_name' => ucfirst($role) . ' ' . Str::random(4),
            'role_key' => $role, 'is_active' => true, 'user_id' => $user->id,
        ]);

        return $user;
    }

    // ── Who the portal says you are, and where it puts you back ─────────────

    /**
     * The header greets a client by name, not by email address.
     *
     * `$user->name` falls back to the email when there is no `hr_people` record, and a project
     * client has none by design — they do not work here. Their name is on the membership, typed by
     * whoever invited them.
     */
    public function test_the_portal_greets_a_client_by_the_name_on_their_membership(): void
    {
        ProjectMember::where('user_id', $this->client->id)->update(['display_name' => 'Jabulani Tshabalala']);

        $me = $this->actingAs($this->client)
            ->get(route('project-management.portal'))->assertOk()
            ->viewData('page')['props']['me'];

        $this->assertSame('Jabulani Tshabalala', $me['name']);
        $this->assertStringNotContainsString('@', $me['name'],
            'The portal is showing a client their own email address instead of their name.');
    }

    /**
     * Unlocking a session must not move somebody.
     *
     * The lock screen redirected to `/` — the internal application — so a client who locked their
     * own session came back to a 403. Logging in worked and unlocking did not, and the difference
     * was one hard-coded slash rather than the resolver login already uses.
     */
    public function test_unlocking_returns_a_client_to_the_portal_and_not_the_dashboard(): void
    {
        $password = 'a-real-password';
        $this->client->forceFill(['password' => bcrypt($password)])->save();

        $response = $this->actingAs($this->client)
            ->withSession(['session_locked' => true])
            ->post(route('login.unlock'), ['password' => $password]);

        $response->assertRedirect(route('project-management.portal'));
    }

    /** And the greeting on the lock screen itself comes from the same place. */
    public function test_the_lock_screen_greets_a_client_by_name(): void
    {
        ProjectMember::where('user_id', $this->client->id)->update(['display_name' => 'Jabulani Tshabalala']);

        $props = $this->actingAs($this->client)
            ->withSession(['session_locked' => true])
            ->get(route('lock.screen'))->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Jabulani', $props['firstName']);
    }

    /** Staff are unaffected: they still land on the dashboard they can actually open. */
    public function test_unlocking_still_returns_a_staff_user_to_the_dashboard(): void
    {
        $password = 'a-real-password';
        $this->manager->forceFill(['password' => bcrypt($password)])->save();

        $this->actingAs($this->manager)
            ->withSession(['session_locked' => true])
            ->post(route('login.unlock'), ['password' => $password])
            ->assertRedirect(route('dashboard'));
    }
}
