<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Services\TaskService;

/**
 * People, hours and cost over HTTP (EPIC-030, 031, 040).
 *
 * The one that matters most is the last: a role that may see the work but not the money must not
 * receive the money in the payload. Hiding a tab is not access control.
 */
class DeliveryPageTest extends ProjectManagementTestCase
{
    private Project $project;

    private Task $task;

    private ProjectMember $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['budget_amount' => 100000]);
        $this->task = app(TaskService::class)->create($this->project, [
            'name' => 'Excavate', 'planned_start' => now()->startOfMonth()->toDateString(),
            'planned_end' => now()->startOfMonth()->addDays(4)->toDateString(),
            'budget_amount' => 20000,
        ]);
        $this->member = ProjectMember::create([
            'project_id' => $this->project->id, 'role_key' => 'team_member',
            'display_name' => 'Thabo Nkosi', 'is_active' => true,
        ]);
    }

    private function props(): array
    {
        return $this->get(route('project-management.delivery', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_the_page_carries_the_team_the_hours_and_the_cost(): void
    {
        $props = $this->actingAs($this->manager)->props();

        $this->assertSame('Thabo Nkosi', $props['team'][0]['name']);
        $this->assertSame(0.0, $props['hours']['approved_hours']);
        $this->assertSame(100000.0, $props['cost']['budget']);
    }

    public function test_someone_can_be_put_on_a_task_through_the_page(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $this->task->uuid]), [
                'member_id' => $this->member->uuid, 'role' => 'Foreman', 'allocation_pct' => 60,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(60, TaskAssignment::first()->allocation_pct);
    }

    public function test_an_over_allocation_is_refused_with_a_readable_message(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $this->task->uuid]), [
                'member_id' => $this->member->uuid, 'allocation_pct' => 150,
            ])
            ->assertSessionHasErrors('allocation_pct');

        $this->assertSame(0, TaskAssignment::count());
    }

    public function test_hours_are_logged_and_submitted_in_one_step(): void
    {
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $this->member->uuid, 'task_id' => $this->task->uuid,
                'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Booking and then forgetting to submit is the commonest way hours go unpaid.
        $this->assertSame('submitted', TimeEntry::first()->status);
    }

    public function test_booking_past_the_end_of_a_day_comes_back_on_the_hours_field(): void
    {
        $day = now()->subDay()->toDateString();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $this->member->uuid, 'worked_on' => $day, 'hours' => 20,
            ]);

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $this->member->uuid, 'worked_on' => $day, 'hours' => 8,
            ])
            ->assertSessionHasErrors('hours');

        $this->assertSame(1, TimeEntry::count());
    }

    public function test_hours_are_approved_costed_and_reflected_in_the_summary(): void
    {
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $this->member->uuid, 'task_id' => $this->task->uuid,
                'worked_on' => now()->subDay()->toDateString(), 'hours' => 8, 'rate_per_hour' => 300,
            ]);

        $entry = TimeEntry::firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('project-management.time.approve', [$this->project->uuid, $entry->uuid]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($this->manager)->props();

        $this->assertSame(8.0, $props['hours']['approved_hours']);
        $this->assertSame(2400.0, $props['cost']['labour']);
        $this->assertSame(2400.0, $props['cost']['actual']);
    }

    public function test_a_rejection_needs_a_reason_the_person_can_act_on(): void
    {
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $this->member->uuid, 'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
            ]);

        $entry = TimeEntry::firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('project-management.time.reject', [$this->project->uuid, $entry->uuid]), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->manager)
            ->post(route('project-management.time.reject', [$this->project->uuid, $entry->uuid]), [
                'reason' => 'You were on site for four hours, not eight.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $entry->fresh()->status);
    }

    /**
     * A subcontractor may need to see the programme and may not see the margin. Withholding the tab
     * is not enough — the numbers must not be in the payload at all.
     */
    public function test_cost_is_absent_from_the_payload_for_a_role_that_may_not_see_it(): void
    {
        $props = $this->actingAs($this->fieldWorker)->props();

        $this->assertNull($props['cost']);
        $this->assertFalse($props['can']['costs']);

        // And present for someone who holds the grant.
        $this->assertNotNull($this->actingAs($this->manager)->props()['cost']);
    }

    public function test_a_field_worker_cannot_approve_hours(): void
    {
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $this->member->uuid, 'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
            ]);

        $entry = TimeEntry::firstOrFail();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.time.approve', [$this->project->uuid, $entry->uuid]))
            ->assertForbidden();

        $this->assertSame('submitted', $entry->fresh()->status);
    }

    public function test_a_member_from_another_project_cannot_be_assigned_through_this_page(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $stranger = ProjectMember::create([
            'project_id' => $other->id, 'role_key' => 'team_member',
            'display_name' => 'Stranger', 'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $this->task->uuid]), [
                'member_id' => $stranger->uuid,
            ])
            ->assertNotFound();

        $this->assertSame(0, TaskAssignment::count());
    }

    // ── Hours on the row of the person who worked them ───────────────────────

    /**
     * Approving somebody's hours shows up where they work from.
     *
     * The team row carried what a person was committed to and what HR recorded for them, and never
     * the hours themselves. So an approver signed off a timesheet and the page they signed it on
     * looked exactly the same afterwards — there was no way to tell a full timesheet from an empty
     * one, or a signed-off one from a submitted one still waiting.
     */
    public function test_a_persons_row_shows_what_they_booked_and_what_was_approved(): void
    {
        $entry = app(\App\Modules\Operations\ProjectManagement\Services\TimesheetService::class)
            ->log($this->project, $this->member, [
                'task_id' => $this->task->uuid, 'hours' => 6, 'worked_on' => now()->startOfMonth()->toDateString(),
            ]);

        $row = collect($this->actingAs($this->manager)->props()['team'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertSame(6.0, $row['booked']);
        $this->assertSame(0.0, $row['approved'], 'Nothing has been signed off yet.');

        $entry->forceFill(['status' => 'approved'])->save();

        $row = collect($this->actingAs($this->manager)->props()['team'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertSame(6.0, $row['approved'], 'Approving has to change something the approver can see.');
    }

    public function test_hours_still_waiting_are_called_out_separately(): void
    {
        // Booked and approved alone cannot distinguish "not submitted" from "submitted and nobody
        // has looked", and only one of those is somebody's job to clear.
        $entry = app(\App\Modules\Operations\ProjectManagement\Services\TimesheetService::class)
            ->log($this->project, $this->member, [
                'task_id' => $this->task->uuid, 'hours' => 4, 'worked_on' => now()->startOfMonth()->toDateString(),
            ]);

        $entry->forceFill(['status' => 'submitted'])->save();

        $row = collect($this->actingAs($this->manager)->props()['team'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertSame(4.0, $row['awaiting']);
        $this->assertSame(0.0, $row['approved']);
    }

    public function test_rejected_hours_are_counted_nowhere(): void
    {
        /*
         * A rejected entry is a record of something that did not happen. Counting it as booked
         * would have this page disagree with the invoice built from the same hours.
         */
        $entry = app(\App\Modules\Operations\ProjectManagement\Services\TimesheetService::class)
            ->log($this->project, $this->member, [
                'task_id' => $this->task->uuid, 'hours' => 3, 'worked_on' => now()->startOfMonth()->toDateString(),
            ]);

        $entry->forceFill(['status' => 'rejected'])->save();

        $row = collect($this->actingAs($this->manager)->props()['team'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertSame(0.0, $row['booked']);
    }

    public function test_a_person_with_no_hours_reads_as_zero_rather_than_missing(): void
    {
        $row = collect($this->actingAs($this->manager)->props()['team'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertSame(0.0, $row['booked']);
        $this->assertSame(0.0, $row['approved']);
    }

    public function test_hours_outside_the_window_are_not_counted(): void
    {
        // The panel is titled with its window. Counting a figure from outside it would make the
        // total unexplainable against the entries listed right beneath.
        $entry = app(\App\Modules\Operations\ProjectManagement\Services\TimesheetService::class)
            ->log($this->project, $this->member, [
                'task_id' => $this->task->uuid, 'hours' => 5,
                'worked_on' => now()->startOfMonth()->subMonth()->toDateString(),
            ]);

        $entry->forceFill(['status' => 'approved'])->save();

        $row = collect($this->actingAs($this->manager)->props()['team'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertSame(0.0, $row['approved']);
    }

    // ── Letting a client in ──────────────────────────────────────────────────

    /**
     * The team list says who can be given a portal login, and who already has one.
     *
     * The invitation endpoint existed and no screen in the application called it, so a client could
     * be added to a project and then had no way to be let in — the only route was the console.
     */
    public function test_an_external_member_without_a_login_can_be_invited(): void
    {
        $client = ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Acme Ltd',
            'role_key' => 'client', 'is_active' => true, 'email' => 'ops@acme.test',
        ]);

        $row = collect($this->actingAs($this->manager)->props()['members'])
            ->firstWhere('id', $client->uuid);

        $this->assertTrue($row['external'], 'A client is somebody the portal is for.');
        $this->assertFalse($row['has_login']);
        $this->assertSame('ops@acme.test', $row['email']);
    }

    public function test_an_employee_is_not_offered_a_portal_invitation(): void
    {
        // Staff sign in through the front door. Offering a portal invitation would create a second
        // account for somebody who already has one.
        $row = collect($this->actingAs($this->manager)->props()['members'])
            ->firstWhere('id', $this->member->uuid);

        $this->assertFalse($row['external'], 'team_member is an internal role.');
    }

    public function test_somebody_who_already_has_a_login_is_marked_as_such(): void
    {
        $user = \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'email' => 'c' . \Illuminate\Support\Str::random(6) . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'company_id' => $this->project->company_id,
        ]);

        $client = ProjectMember::create([
            'project_id' => $this->project->id, 'display_name' => 'Already in',
            'role_key' => 'client', 'is_active' => true, 'user_id' => $user->id,
        ]);

        $row = collect($this->actingAs($this->manager)->props()['members'])
            ->firstWhere('id', $client->uuid);

        $this->assertTrue($row['has_login'], 'Inviting them again would be a second account.');
    }
}
