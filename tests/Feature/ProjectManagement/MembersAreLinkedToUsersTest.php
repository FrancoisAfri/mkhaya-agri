<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\TeamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A project member points at the account they belong to.
 *
 * `user_id` was only set when a caller passed one, and neither way people actually add somebody
 * does: from the employee directory links the HR record and nothing else, and by name links nothing
 * at all. Members were created with a display name, an email, and no account behind them.
 *
 * It reads as a null column and behaves as a missing feature. Everything answering "what is
 * assigned to me" — My Work, task notifications, due-date reminders — matches on `user_id`, so all
 * of it looked straight past people whose names were on the cards. My Work was reported empty by
 * somebody holding a board full of their own tasks, and this is why.
 */
class MembersAreLinkedToUsersTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function team(): TeamService
    {
        return app(TeamService::class);
    }

    private function employee(int $userId, string $first, string $surname): int
    {
        return DB::table('hr_people')->insertGetId([
            'user_id' => $userId, 'first_name' => $first, 'surname' => $surname,
            'status' => 1, 'company_id' => $this->project->company_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Adding somebody ──────────────────────────────────────────────────────

    public function test_adding_a_member_by_email_finds_the_account_behind_it(): void
    {
        $member = $this->team()->add($this->project, [
            'display_name' => 'Francois keou',
            'email' => $this->manager->email,
            'role_key' => 'team_member',
        ]);

        $this->assertSame($this->manager->id, $member->user_id,
            'A member was created with an email that matches an account and no link to it.');
    }

    public function test_an_explicit_user_still_wins(): void
    {
        $member = $this->team()->add($this->project, [
            'display_name' => 'Somebody',
            'user_id' => $this->fieldWorker->id,
            'email' => $this->manager->email,
            'role_key' => 'team_member',
        ]);

        $this->assertSame($this->fieldWorker->id, $member->user_id);
    }

    public function test_a_member_added_with_no_email_stays_unlinked(): void
    {
        $member = $this->team()->add($this->project, [
            'display_name' => 'A name on a card', 'role_key' => 'team_member',
        ]);

        // Correct, not a gap: a name is not an account, and guessing one from it is how a member
        // ends up pointing at somebody else entirely.
        $this->assertNull($member->user_id);
    }

    /**
     * A platform administrator has no `users.company_id` — they are above tenancy — and they are
     * very often the person running the first projects on a new install. A strict company filter
     * excluded exactly the account most likely to be looked for.
     */
    public function test_an_account_belonging_to_no_company_is_still_found(): void
    {
        $admin = \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'admin+' . Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'company_id' => null, 'is_platform_admin' => true,
        ]);

        $member = $this->team()->add($this->project, [
            'display_name' => 'Platform admin', 'email' => $admin->email, 'role_key' => 'team_member',
        ]);

        $this->assertSame($admin->id, $member->user_id);
    }

    // ── The backfill ─────────────────────────────────────────────────────────

    private function unlinkedMember(array $attributes = []): ProjectMember
    {
        return ProjectMember::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'company_id' => $this->project->company_id,
            'user_id' => null,
            'display_name' => 'Francois keou',
            'email' => $this->manager->email,
            'role_key' => 'team_member',
            'is_active' => true,
        ], $attributes));
    }

    public function test_the_sweep_reports_without_changing_anything_by_default(): void
    {
        $member = $this->unlinkedMember();

        $this->artisan('pm:link-members-to-users')->assertSuccessful();

        $this->assertNull($member->fresh()->user_id, 'A dry run wrote to the team.');
    }

    public function test_the_sweep_links_a_member_by_email(): void
    {
        $member = $this->unlinkedMember();

        $this->artisan('pm:link-members-to-users --write')->assertSuccessful();

        $this->assertSame($this->manager->id, $member->fresh()->user_id);
    }

    public function test_the_sweep_leaves_a_member_who_already_has_an_account(): void
    {
        $member = $this->unlinkedMember(['user_id' => $this->fieldWorker->id]);

        $this->artisan('pm:link-members-to-users --write')->assertSuccessful();

        $this->assertSame($this->fieldWorker->id, $member->fresh()->user_id,
            'An existing link was overwritten by a guess from the email.');
    }

    public function test_the_sweep_leaves_a_member_with_nothing_to_match_on(): void
    {
        $member = $this->unlinkedMember(['email' => null, 'display_name' => 'Just a name']);

        $this->artisan('pm:link-members-to-users --write')->assertSuccessful();

        $this->assertNull($member->fresh()->user_id);
    }

    /** Names are never matched: being wrong here shows one person another person's work. */
    public function test_the_sweep_does_not_match_on_names(): void
    {
        $named = $this->employee($this->manager->id, 'Thandi', 'Mokoena');
        $member = $this->unlinkedMember(['email' => null, 'display_name' => 'Thandi Mokoena']);

        $this->artisan('pm:link-members-to-users --write')->assertSuccessful();

        $this->assertNull($member->fresh()->user_id,
            'A member was linked to an account on the strength of a matching name.');
        unset($named);
    }

    // ── What it was all for ──────────────────────────────────────────────────

    /**
     * The whole point: once the member points at the account, the work assigned to that member
     * shows up on that person's board. This is the bug as it was reported — a full project board
     * and an empty My Work.
     */
    public function test_work_reaches_my_work_once_the_member_is_linked(): void
    {
        $member = $this->unlinkedMember();

        $task = app(\App\Modules\Operations\ProjectManagement\Services\TaskService::class)
            ->create($this->project, ['name' => 'Excavate', 'planned_end' => '2026-03-06']);

        DB::table('pm_task_assignments')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->project->company_id,
            'project_id' => $this->project->id, 'task_id' => $task->id, 'member_id' => $member->id,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $board = fn () => $this->actingAs($this->manager)
            ->get(route('project-management.field'))->assertOk()
            ->viewData('page')['props']['tasks']['not_started'];

        // Before: the name is on the card, the board is empty, and nothing explains the difference.
        $this->assertCount(0, $board());

        $this->artisan('pm:link-members-to-users --write')->assertSuccessful();

        $after = $board();
        $this->assertCount(1, $after, 'Linking the member did not put their work on their board.');
        $this->assertSame('Excavate', $after[0]['name']);
    }

    /**
     * The same person on one project twice, once with an account and once without.
     *
     * `pm_project_members_unique` covers (company, project, user) for live rows, so linking the
     * second would collide — correctly, because they are already on the project. The first live run
     * of this hit exactly that and took the whole tenant's pass down with it.
     */
    public function test_a_duplicate_member_is_reported_rather_than_linked(): void
    {
        $already = $this->unlinkedMember(['user_id' => $this->manager->id, 'display_name' => 'Francois keou']);
        $duplicate = $this->unlinkedMember(['display_name' => 'Francois keou']);

        $this->artisan('pm:link-members-to-users --write')
            ->expectsOutputToContain('already on this project')
            ->assertSuccessful();

        $this->assertNull($duplicate->fresh()->user_id,
            'A second member row for the same person on the same project was linked anyway.');
        $this->assertSame($this->manager->id, $already->fresh()->user_id);
    }

    /** One bad row must not take the rest of the estate's members with it. */
    public function test_a_duplicate_does_not_stop_the_other_members_being_linked(): void
    {
        $this->unlinkedMember(['user_id' => $this->manager->id]);
        $duplicate = $this->unlinkedMember();

        $other = $this->makeProject(['name' => 'Another job', 'company_id' => $this->project->company_id]);
        $linkable = ProjectMember::create([
            'uuid' => (string) Str::uuid(), 'project_id' => $other->id,
            'company_id' => $other->company_id, 'user_id' => null,
            'display_name' => 'Francois keou', 'email' => $this->manager->email,
            'role_key' => 'team_member', 'is_active' => true,
        ]);

        $this->artisan('pm:link-members-to-users --write')->assertSuccessful();

        $this->assertNull($duplicate->fresh()->user_id);
        $this->assertSame($this->manager->id, $linkable->fresh()->user_id,
            'A duplicate on one project stopped a good row on another being linked.');
    }
}
