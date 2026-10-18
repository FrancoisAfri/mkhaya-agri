<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TaskWatcher;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Services\AssignmentService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * People on tasks, and hours against them (EPIC-030, EPIC-031).
 *
 * Two things carry the weight here: that over-committing a person is noticed when the assignment is
 * made rather than when the week goes wrong, and that approving hours is a different act from
 * booking them — with a different person doing it.
 */
class PeopleAndTimeTest extends ProjectManagementTestCase
{
    private Project $project;

    private Task $task;

    private ProjectMember $thabo;

    private AssignmentService $assignments;

    private TimesheetService $timesheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        $this->task = app(TaskService::class)->create($this->project, [
            'name' => 'Excavate', 'planned_start' => '2026-03-02', 'planned_end' => '2026-03-06',
        ]);
        $this->thabo = $this->member('Thabo Nkosi');
        $this->assignments = app(AssignmentService::class);
        $this->timesheets = app(TimesheetService::class);
    }

    private function member(string $name, ?int $userId = null): ProjectMember
    {
        return ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $userId,
            'role_key'     => 'team_member',
            'display_name' => $name,
            'is_active'    => true,
        ]);
    }

    // ── Assignments ──────────────────────────────────────────────────────────

    public function test_someone_can_be_put_on_a_task_with_a_share_of_their_time(): void
    {
        $assignment = $this->assignments->assign($this->task, $this->thabo, [
            'role' => 'Foreman', 'allocation_pct' => 50, 'is_lead' => true,
        ]);

        $this->assertSame(50, $assignment->allocation_pct);
        $this->assertTrue($assignment->is_lead);
    }

    public function test_assigning_the_same_person_twice_updates_rather_than_duplicates(): void
    {
        $this->assignments->assign($this->task, $this->thabo, ['allocation_pct' => 50]);
        $this->assignments->assign($this->task, $this->thabo, ['allocation_pct' => 80]);

        $this->assertSame(1, TaskAssignment::count());
        $this->assertSame(80, TaskAssignment::first()->allocation_pct);
    }

    public function test_only_one_person_can_be_answerable_for_a_task(): void
    {
        $sipho = $this->member('Sipho Dlamini');

        $this->assignments->assign($this->task, $this->thabo, ['is_lead' => true]);
        $this->assignments->assign($this->task, $sipho, ['is_lead' => true]);

        // "Answerable" stops meaning anything when two people are.
        $this->assertSame(1, TaskAssignment::where('is_lead', true)->count());
        $this->assertSame($sipho->id, TaskAssignment::where('is_lead', true)->first()->member_id);
    }

    public function test_being_assigned_subscribes_someone_to_the_task(): void
    {
        $withLogin = $this->member('Nomsa Khumalo', $this->fieldWorker->id);

        $this->assignments->assign($this->task, $withLogin);

        $watcher = TaskWatcher::where('task_id', $this->task->id)->first();

        $this->assertSame($this->fieldWorker->id, (int) $watcher->user_id);
        $this->assertSame('assignee', $watcher->source);
    }

    public function test_a_person_who_has_left_the_project_cannot_be_assigned(): void
    {
        $this->thabo->forceFill(['is_active' => false])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer active');

        $this->assignments->assign($this->task, $this->thabo->fresh());
    }

    public function test_someone_from_another_project_cannot_be_assigned(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $stranger = ProjectMember::create([
            'project_id' => $other->id, 'role_key' => 'team_member',
            'display_name' => 'Stranger', 'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a member of this project');

        $this->assignments->assign($this->task, $stranger);
    }

    /**
     * The point of tracking allocation: nobody plans to over-commit a foreman, they just do it one
     * task at a time. This is the cheap moment to notice.
     */
    public function test_overlapping_assignments_show_where_someone_is_over_committed(): void
    {
        $second = app(TaskService::class)->create($this->project, [
            'name' => 'Shutter', 'planned_start' => '2026-03-04', 'planned_end' => '2026-03-10',
        ]);

        $this->assignments->assign($this->task, $this->thabo, ['allocation_pct' => 60]);
        $this->assignments->assign($second, $this->thabo, ['allocation_pct' => 60]);

        $workload = $this->assignments->workload(
            $this->thabo,
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        // 4–6 March carry both tasks at 60% each.
        $this->assertSame(120, $workload['days']['2026-03-04']['allocated']);
        $this->assertSame(60, $workload['days']['2026-03-02']['allocated']);
        $this->assertSame(['2026-03-04', '2026-03-05', '2026-03-06'], $workload['overloaded']);
    }

    public function test_the_team_view_reports_each_persons_worst_day(): void
    {
        $this->assignments->assign($this->task, $this->thabo, ['allocation_pct' => 40]);

        $team = $this->assignments->team(
            $this->project,
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $thabo = collect($team)->firstWhere('name', 'Thabo Nkosi');

        $this->assertSame(1, $thabo['tasks']);
        $this->assertSame(40, $thabo['peak']);
        $this->assertSame(0, $thabo['overloaded']);
    }

    // ── Time ─────────────────────────────────────────────────────────────────

    public function test_hours_are_booked_as_a_draft(): void
    {
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'task_id' => $this->task->uuid, 'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
        ]);

        $this->assertSame('draft', $entry->status);
        $this->assertSame('8.00', $entry->hours);
        $this->assertSame($this->task->id, $entry->task_id);
    }

    public function test_a_day_cannot_hold_more_than_twenty_four_hours(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $this->timesheets->log($this->project, $this->thabo, ['worked_on' => $yesterday, 'hours' => 20]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a day only has 24');

        $this->timesheets->log($this->project, $this->thabo, ['worked_on' => $yesterday, 'hours' => 6]);
    }

    public function test_hours_cannot_be_booked_for_a_day_that_has_not_happened(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has not happened yet');

        $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->addWeek()->toDateString(), 'hours' => 8,
        ]);
    }

    // ── Whether this project needs sign-off at all ───────────────────────────

    /**
     * A project can be set to approve hours as they are booked.
     *
     * Approval is right for a job billed to a client, where an hour is money and somebody signs for
     * it. On an internal project the same routing sends every entry to a manager who approves it on
     * sight — and a queue that is always rubber-stamped teaches people that queues are rubber
     * stamps, which is worse than not having one.
     */
    public function test_hours_are_approved_on_booking_when_the_project_does_not_require_sign_off(): void
    {
        $this->project->forceFill(['timesheet_approval_required' => false])->save();

        $this->actingAs($this->fieldWorker);

        $entry = $this->timesheets->log($this->project->fresh(), $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8, 'rate_per_hour' => 250,
        ]);

        $submitted = $this->timesheets->submit($entry);

        $this->assertSame('approved', $submitted->status);
        // Approved means costed — that is what the rest of the module reads it for.
        $this->assertSame(2000.0, (float) $submitted->cost_amount);
    }

    /** The default is unchanged: an existing project keeps routing hours for approval. */
    public function test_hours_still_wait_for_approval_by_default(): void
    {
        // Read back from the database: the column default is what a new project inherits, and the
        // instance returned by create() never saw it.
        $this->assertTrue((bool) $this->project->fresh()->timesheet_approval_required);

        $this->actingAs($this->fieldWorker);

        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
        ]);

        $this->assertSame('submitted', $this->timesheets->submit($entry)->status);
    }

    /**
     * Separation of duties. Approving your own hours is not approval, and a timesheet system that
     * allows it is a system nobody outside the company will accept.
     */
    public function test_a_person_cannot_approve_their_own_hours(): void
    {
        $this->actingAs($this->fieldWorker);

        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
        ]);
        $this->timesheets->submit($entry);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('someone other than the person who booked them');

        $this->timesheets->approve($entry->fresh());
    }

    public function test_approving_costs_the_hours_posts_them_and_records_evidence(): void
    {
        $this->actingAs($this->fieldWorker);
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8, 'rate_per_hour' => 250,
        ]);
        $this->timesheets->submit($entry);

        $this->actingAs($this->manager);
        $approved = $this->timesheets->approve($entry->fresh());

        $this->assertSame('approved', $approved->status);
        $this->assertSame('2000.00', $approved->cost_amount);
        // The sink hands back a receipt, whichever sink is installed.
        $this->assertStringStartsWith('PM-TIME-', $approved->sink_reference);

        // No task was named, so the hours attach to the project — project-level time is legitimate.
        $this->assertDatabaseHas('pm_evidence_events', [
            'event_type'   => 'time.approved',
            'subject_type' => 'project',
        ]);
    }

    public function test_only_submitted_hours_can_be_approved(): void
    {
        $this->actingAs($this->manager);
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only submitted hours');

        $this->timesheets->approve($entry);
    }

    public function test_a_rejection_has_to_say_why(): void
    {
        $this->actingAs($this->fieldWorker);
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
        ]);
        $this->timesheets->submit($entry);

        $this->actingAs($this->manager);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->timesheets->reject($entry->fresh(), '  ');
    }

    public function test_a_rejected_entry_can_be_fixed_and_resubmitted(): void
    {
        $this->actingAs($this->fieldWorker);
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 12,
        ]);
        $this->timesheets->submit($entry);

        $this->actingAs($this->manager);
        $this->timesheets->reject($entry->fresh(), 'You were on site for eight, not twelve.');

        $resubmitted = $this->timesheets->submit($entry->fresh());

        $this->assertSame('submitted', $resubmitted->status);
        $this->assertNull($resubmitted->rejection_reason);
    }

    /**
     * Withdrawing an approval does not erase the evidence already banked — a correction is a new
     * event, never a deletion (PM-ADR-002).
     */
    public function test_withdrawing_an_approval_leaves_the_evidence_standing(): void
    {
        $this->actingAs($this->fieldWorker);
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8, 'rate_per_hour' => 100,
        ]);
        $this->timesheets->submit($entry);

        $this->actingAs($this->manager);
        $this->timesheets->approve($entry->fresh());

        $evidenceBefore = \App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::count();

        $withdrawn = $this->timesheets->withdrawApproval($entry->fresh(), 'Approved against the wrong task.');

        $this->assertSame('submitted', $withdrawn->status);
        $this->assertNull($withdrawn->cost_amount);
        $this->assertSame($evidenceBefore, \App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::count());
    }

    public function test_the_summary_separates_agreed_hours_from_claimed_ones(): void
    {
        $this->actingAs($this->fieldWorker);
        $approved = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDays(2)->toDateString(), 'hours' => 8, 'rate_per_hour' => 200,
        ]);
        $this->timesheets->submit($approved);

        $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 6,
        ]);

        $this->actingAs($this->manager);
        $this->timesheets->approve($approved->fresh());

        $summary = $this->timesheets->summary($this->project);

        $this->assertSame(8.0, $summary['approved_hours']);
        $this->assertSame(6.0, $summary['pending_hours']);
        $this->assertSame(1600.0, $summary['cost']);
    }

    public function test_an_approved_entry_knows_it_is_locked(): void
    {
        $this->actingAs($this->fieldWorker);
        $entry = $this->timesheets->log($this->project, $this->thabo, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 8,
        ]);
        $this->timesheets->submit($entry);

        $this->actingAs($this->manager);
        $this->timesheets->approve($entry->fresh());

        $this->assertTrue(TimeEntry::first()->isLocked());
    }
}
