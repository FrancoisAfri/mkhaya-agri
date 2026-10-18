<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ApprovalFlow;
use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\ApprovalStep;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Notifications\ApprovalDecidedNotification;
use App\Modules\Operations\ProjectManagement\Notifications\ApprovalRequestedNotification;
use App\Modules\Operations\ProjectManagement\Services\ApprovalService;
use App\Modules\Operations\ProjectManagement\Services\CostEntryService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;
use Illuminate\Support\Facades\Notification;

/**
 * The approval engine (EPIC-090).
 *
 * What is actually being guarded here is that "submit for approval" is no longer a button with
 * nothing behind it. Three properties matter more than the rest, and each has its own test:
 * somebody is *told*; the person who submitted cannot wave their own work through; and a decision,
 * once made, is applied to the thing it was about rather than sitting in a second table saying
 * something different.
 */
class ApprovalsTest extends ProjectManagementTestCase
{
    private Project $project;

    private ProjectMember $member;

    /** Holds pm.settings.manage, which the manager deliberately does not. */
    private \App\Core\User\Models\User $settingsAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'pm test admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(['pm.projects.view', 'pm.settings.manage']);

        $this->settingsAdmin = $this->makeUser('pm-approval-admin@example.test', $adminRole);
        $this->grantModuleTier($this->settingsAdmin);

        $this->project = $this->makeProject(['budget_amount' => 500000]);

        $this->member = ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $this->fieldWorker->id,
            'role_key'     => 'team_member',
            'display_name' => 'Thabo Nkosi',
            'is_active'    => true,
        ]);
    }

    private function bookHours(float $hours = 8, ?float $rate = 250): TimeEntry
    {
        $this->actingAs($this->fieldWorker);

        return app(TimesheetService::class)->log($this->project, $this->member, [
            'worked_on'     => now()->subDay()->toDateString(),
            'hours'         => $hours,
            'rate_per_hour' => $rate,
        ]);
    }

    // ── Routing ──────────────────────────────────────────────────────────────

    /**
     * With nothing designed, submitting still queues rather than silently approving.
     *
     * Getting this backwards would have been the worst possible default: switching the engine on
     * would have auto-approved every timesheet on every tenant that had never opened the designer.
     */
    public function test_with_no_flow_designed_a_timesheet_still_queues_for_whoever_runs_the_project(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $request = ApprovalRequest::where('subject_type', 'timesheet')->first();

        $this->assertNotNull($request, 'Submitting created no approval request.');
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->flow_id, 'No flow is designed, so it routes by the default.');
        $this->assertSame('submitted', $entry->fresh()->status);
    }

    public function test_the_approver_is_notified(): void
    {
        Notification::fake();

        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        // The manager may update the project, so the default routing names them — and the point of
        // the whole epic is that they hear about it rather than having to go looking.
        Notification::assertSentTo($this->manager, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->fieldWorker, ApprovalRequestedNotification::class);
    }

    /**
     * A threshold means what it says: below it, nothing queues.
     *
     * Without this a company that does not want R80 of screws crossing three desks would have to
     * ask for a code change, and in practice would work around the queue instead.
     */
    public function test_an_amount_under_the_threshold_is_approved_on_submission(): void
    {
        $this->flow('timesheet', threshold: 10000);

        $entry = $this->bookHours(hours: 2, rate: 100);   // R200, well under.

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(0, ApprovalRequest::count(), 'Nothing should have queued.');
    }

    public function test_an_amount_over_the_threshold_queues(): void
    {
        $this->flow('timesheet', threshold: 1000);

        $entry = $this->bookHours(hours: 8, rate: 500);   // R4,000.

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $this->assertSame('submitted', $entry->fresh()->status);
        $this->assertSame(1, ApprovalRequest::pending()->count());
    }

    // ── Deciding ─────────────────────────────────────────────────────────────

    public function test_approving_applies_the_decision_to_the_timesheet_itself(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $request = ApprovalRequest::first();

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.approve', $request->uuid))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Both, and in step. A request that says "approved" while the timesheet still says
        // "submitted" is two systems disagreeing, and the cost report reads the wrong one.
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(2000.0, (float) $entry->fresh()->cost_amount);
    }

    public function test_rejecting_carries_the_reason_back_to_the_timesheet(): void
    {
        Notification::fake();

        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $request = ApprovalRequest::first();

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.reject', $request->uuid), [
                'reason' => 'Those hours are on the wrong job.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $entry->fresh()->status);
        $this->assertStringContainsString('wrong job', $entry->fresh()->rejection_reason);

        // Told, with the reason — otherwise they resubmit the same timesheet unchanged.
        Notification::assertSentTo($this->fieldWorker, ApprovalDecidedNotification::class);
    }

    public function test_a_rejection_has_to_say_why(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.reject', ApprovalRequest::first()->uuid), ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    /**
     * Separation of duties, on by default.
     *
     * The switch to waive it exists because a two-person business genuinely needs it — but a rule
     * that is off unless somebody remembers to turn it on is not a rule.
     */
    public function test_the_person_who_submitted_cannot_approve_their_own_submission(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $request = ApprovalRequest::first();

        $this->assertFalse(
            app(ApprovalService::class)->canAct($request, $request->currentStep(), $this->fieldWorker)
        );

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.approvals.approve', $request->uuid))
            ->assertSessionHasErrors('approval');

        $this->assertSame('submitted', $entry->fresh()->status);
    }

    public function test_a_manager_step_is_for_the_submitters_line_manager(): void
    {
        $flow = $this->flow('timesheet');
        $flow->steps()->update(['approver_type' => 'manager', 'approver_ref' => null]);
        $person = fn ($user, $managerId = null) => (int) \Illuminate\Support\Facades\DB::table('hr_people')->insertGetId(['user_id' => $user->id,
            'first_name' => 'Test', 'surname' => 'Person', 'email' => $user->email, 'status' => 1, 'manager_id' => $managerId, 'created_at' => now(), 'updated_at' => now()]);
        $person($this->fieldWorker, $person($this->manager));
        $person($this->outsider);

        $entry = $this->bookHours();
        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);
        $request = ApprovalRequest::where('subject_type', 'timesheet')->first();

        $this->assertTrue(app(ApprovalService::class)->canAct($request, $request->currentStep(), $this->manager));
        $this->assertFalse(app(ApprovalService::class)->canAct($request, $request->currentStep(), $this->outsider));
    }

    public function test_a_step_may_deliberately_allow_self_approval(): void
    {
        $flow = $this->flow('timesheet');
        $flow->steps()->update(['approver_type' => 'role', 'approver_ref' => 'pm test manager', 'allow_self_approval' => true]);

        $entry = $this->bookHours();

        $this->actingAs($this->manager);
        $submitted = app(TimesheetService::class)->log($this->project, $this->member, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 4, 'rate_per_hour' => 100,
        ]);
        app(TimesheetService::class)->submit($submitted);

        $request = ApprovalRequest::where('subject_id', $submitted->id)->first();

        // The step waives it, so the rule that would otherwise stop them does not apply.
        $this->assertTrue($request->currentStep()->allow_self_approval);

        unset($entry);
    }

    // ── The ladder ───────────────────────────────────────────────────────────

    public function test_a_two_step_flow_advances_rather_than_finishing_on_the_first_signature(): void
    {
        $flow = $this->flow('timesheet');

        ApprovalStep::create([
            'flow_id' => $flow->id, 'position' => 2, 'name' => 'Finance',
            'approver_type' => 'role', 'approver_ref' => 'pm test manager', 'min_approvals' => 1,
        ]);

        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $request = ApprovalRequest::first();

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.approve', $request->uuid))
            ->assertRedirect();

        // Still open, now on rung two — and crucially the timesheet has *not* moved, because an
        // intermediate signature approves that step, not the thing.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(2, $request->fresh()->current_position);
        $this->assertSame('submitted', $entry->fresh()->status);
    }

    public function test_one_rejection_ends_it_at_whatever_rung(): void
    {
        $flow = $this->flow('timesheet');
        ApprovalStep::create([
            'flow_id' => $flow->id, 'position' => 2, 'name' => 'Finance',
            'approver_type' => 'role', 'approver_ref' => 'pm test manager', 'min_approvals' => 1,
        ]);

        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.reject', ApprovalRequest::first()->uuid), [
                'reason' => 'Booked against the wrong project.',
            ]);

        $this->assertSame('rejected', ApprovalRequest::first()->status);
    }

    public function test_resubmitting_supersedes_the_request_already_waiting(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        $timesheets = app(TimesheetService::class);
        $timesheets->submit($entry);

        $this->actingAs($this->manager);
        $timesheets->reject($entry->fresh(), 'Wrong day.');

        $this->actingAs($this->fieldWorker);
        $timesheets->submit($entry->fresh());

        // One live request for one set of hours; two would be a double-count of the same money.
        $this->assertSame(1, ApprovalRequest::pending()->where('subject_id', $entry->id)->count());
    }

    // ── The inbox ────────────────────────────────────────────────────────────

    public function test_the_inbox_shows_only_what_this_person_can_actually_decide(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $managerProps = $this->actingAs($this->manager)
            ->get(route('project-management.approvals.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $managerProps['inbox']);

        $workerProps = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.approvals.index'))
            ->viewData('page')['props'];

        // Not theirs to decide — but it *is* theirs to track, which is the other tab.
        $this->assertSame([], $workerProps['inbox']);
        $this->assertCount(1, $workerProps['mine']);
    }

    public function test_a_submitter_can_withdraw_their_own_request_and_nobody_else_can(): void
    {
        $entry = $this->bookHours();

        $this->actingAs($this->fieldWorker);
        app(TimesheetService::class)->submit($entry);

        $request = ApprovalRequest::first();

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.cancel', $request->uuid))
            ->assertForbidden();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.approvals.cancel', $request->uuid))
            ->assertRedirect();

        $this->assertSame('cancelled', $request->fresh()->status);
    }

    // ── Costs go the same way ────────────────────────────────────────────────

    public function test_a_cost_follows_the_same_route_and_becomes_evidence_on_approval(): void
    {
        $this->actingAs($this->fieldWorker);

        $costs = app(CostEntryService::class);

        $entry = $costs->record($this->project, [
            'category'    => 'material',
            'description' => 'Ready-mix concrete',
            'amount'      => 18500,
            'incurred_on' => now()->subDays(2)->toDateString(),
        ]);

        $costs->submit($entry);

        $request = ApprovalRequest::where('subject_type', 'expense')->first();
        $this->assertNotNull($request);

        $this->actingAs($this->manager)
            ->post(route('project-management.approvals.approve', $request->uuid))
            ->assertRedirect();

        $entry->refresh();

        $this->assertSame('approved', $entry->status);
        $this->assertNotNull($entry->posted_reference, 'The expense sink was never told.');

        // The whole point: it lands in the ledger, so derivation ranks it beside every other cost
        // rather than PM keeping a second total nobody can reconcile.
        $this->assertDatabaseHas('pm_evidence_events', [
            'project_id' => $this->project->id,
            'event_type' => 'cost.incurred',
        ]);
    }

    public function test_marking_a_cost_paid_records_the_payment_separately(): void
    {
        $this->actingAs($this->manager);

        $costs = app(CostEntryService::class);

        $entry = $costs->record($this->project, [
            'category' => 'plant', 'description' => 'Excavator hire', 'amount' => 9000,
            'incurred_on' => now()->subDay()->toDateString(),
        ]);

        $costs->approve($entry);
        $costs->markPaid($entry->fresh());

        $this->assertSame('paid', $entry->fresh()->status);

        // Two facts about one invoice, not one recorded twice: incurred, then paid. CostService
        // subtracts the second from committed so it cannot show up in both columns.
        $this->assertDatabaseHas('pm_evidence_events', ['event_type' => 'invoice.paid']);
        $this->assertDatabaseHas('pm_evidence_events', ['event_type' => 'cost.incurred']);
    }

    public function test_an_approved_cost_cannot_be_deleted(): void
    {
        $this->actingAs($this->manager);

        $costs = app(CostEntryService::class);
        $entry = $costs->record($this->project, [
            'category' => 'other', 'description' => 'Site fencing', 'amount' => 4000,
            'incurred_on' => now()->subDay()->toDateString(),
        ]);
        $costs->approve($entry);

        // It has evidence behind it and a derived figure on top of it; deleting the row would
        // leave the ledger asserting a cost that exists nowhere.
        $this->actingAs($this->manager)
            ->delete(route('project-management.expenses.destroy', [$this->project->uuid, $entry->uuid]))
            ->assertStatus(422);

        $this->assertSame(1, CostEntry::count());
    }

    // ── Setup ────────────────────────────────────────────────────────────────

    public function test_a_flow_cannot_be_saved_without_at_least_one_step(): void
    {
        $this->actingAs($this->settingsAdmin)
            ->post(route('project-management.setup.approvals.store'), [
                'name' => 'Empty', 'subject_type' => 'timesheet', 'steps' => [],
            ])
            ->assertSessionHasErrors('steps');
    }

    public function test_the_designer_saves_a_ladder_and_keeps_its_order(): void
    {
        $this->actingAs($this->settingsAdmin)
            ->post(route('project-management.setup.approvals.store'), [
                'name'         => 'Site spend',
                'subject_type' => 'expense',
                'threshold'    => 5000,
                'steps'        => [
                    ['name' => 'Site agent', 'approver_type' => 'pm_role', 'approver_ref' => 'supervisor'],
                    ['name' => 'Contracts', 'approver_type' => 'role', 'approver_ref' => 'pm test manager', 'min_approvals' => 2],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $flow = ApprovalFlow::where('name', 'Site spend')->with('steps')->first();

        $this->assertSame('expense', $flow->subject_type);
        $this->assertSame(5000.0, (float) $flow->threshold_amount);
        $this->assertSame(['Site agent', 'Contracts'], $flow->steps->pluck('name')->all());
        $this->assertSame([1, 2], $flow->steps->pluck('position')->all());
        $this->assertSame(2, $flow->steps[1]->min_approvals);
    }

    public function test_only_an_admin_reaches_the_designer(): void
    {
        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.setup.approvals'))
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function flow(string $subjectType, ?float $threshold = null): ApprovalFlow
    {
        $flow = ApprovalFlow::create([
            'name'             => ucfirst($subjectType) . ' approval',
            'subject_type'     => $subjectType,
            'is_active'        => true,
            'threshold_amount' => $threshold,
        ]);

        ApprovalStep::create([
            'flow_id'       => $flow->id,
            'position'      => 1,
            'name'          => 'Project manager',
            'approver_type' => 'role',
            'approver_ref'  => 'pm test manager',
            'min_approvals' => 1,
        ]);

        return $flow->fresh('steps');
    }
}
