<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowAuditEvent;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNodeRun;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTimer;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\TickService;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Services\WorkflowRunner;
use Illuminate\Support\Facades\DB;

/** The PRS §24 acceptance matrix, at the engine level. */
class WorkflowEngineTest extends WorkflowTestCase
{
    public function test_wf001_publishing_creates_an_immutable_version_and_edits_make_a_new_draft(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $v1 = $d->currentVersion;
        $this->assertSame(['published', 1], [$v1->status, $v1->version]);
        $this->assertSame('published', $d->status);

        $graph = $v1->graph;
        $graph['nodes'][1]['name'] = 'Renamed';
        $v2 = app(DefinitionService::class)->saveDraft($d, $graph, 'Rename');
        $this->assertSame([2, 'draft'], [$v2->version, $v2->status]);
        $this->assertSame('Manager approval', $v1->fresh()->graph['nodes'][1]['name'], 'The published version never changes.');

        app(DefinitionService::class)->submit($d->refresh(), $this->admin, 'Rename');
        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame($v2->id, $d->fresh()->current_version_id);
    }

    public function test_wf002_a_missing_approval_route_blocks_publication_with_the_node_named(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['mgr', 'approval', 'Manager approval', ['assignee' => ['type' => 'line_manager']]], ['ok', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'mgr'], ['mgr', 'ok', ['outcome' => 'approved']]]);
        $this->settings(['publication_requires_review' => false]);
        $this->actingAs($this->admin);
        $d = app(DefinitionService::class)->create(['name' => 'Broken', 'process_owner_id' => $this->admin->id], $graph);

        try {
            app(DefinitionService::class)->submit($d, $this->admin, 'try');
            $this->fail('Publication should have been blocked.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('"Manager approval" has no route for the outcome "rejected"', $e->getMessage());
        }
        $problem = collect($d->workingVersion()->validation)->firstWhere('node', 'mgr');
        $this->assertNotEmpty($problem['fix']);
        $this->assertSame('draft', $d->fresh()->status);
        $this->assertSame(1, WorkflowAuditEvent::where('action', 'version.submit')->where('outcome', 'failed')->count());
    }

    public function test_validator_finds_dead_ends_unreachable_steps_and_bad_parallel_joins(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['split', 'parallel', 'Split'], ['a', 'user_task', 'A', ['assignee' => ['type' => 'requester']]],
            ['b', 'user_task', 'B', ['assignee' => ['type' => 'requester']]], ['orphan', 'user_task', 'Orphan', ['assignee' => ['type' => 'requester']]],
            ['dec', 'decision', 'Amount?'], ['end', 'end', 'End'],
        ], [['start', 'split'], ['split', 'a'], ['split', 'b'], ['a', 'end'], ['b', 'dec'], ['dec', 'end', ['condition' => ['field' => 'amount', 'operator' => 'gt', 'value' => 5]]], ['orphan', 'end']]);
        $this->actingAs($this->admin);
        $d = app(DefinitionService::class)->create(['name' => 'Bad'], $graph);
        $messages = collect(app(\App\Modules\Workflow\Services\GraphValidator::class)->validate($graph, $d))->pluck('message')->implode("\n");
        $this->assertStringContainsString('"Orphan" can never be reached', $messages);
        $this->assertStringContainsString('do not all reach the same Join', $messages);
        $this->assertStringContainsString('needs exactly one default route', $messages);
    }

    public function test_wf003_submitting_creates_one_instance_with_a_reference(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $i = $this->startAs($this->requester, $d, ['amount' => 100]);
        $this->assertSame('running', $i->status);
        $this->assertMatchesRegularExpression('/^WF-\d{4}-000001$/', $i->reference);
        $this->assertSame($d->current_version_id, $i->version_id);
        $task = $this->openTask($i, 'mgr');
        $this->assertSame($this->manager->id, $task->assignee_id, 'Line manager resolved from hr_people.manager_id.');
        $this->assertSame(1, WorkflowAuditEvent::where('action', 'instance.started')->where('instance_id', $i->id)->count());
        $this->assertDatabaseHas('workflow_events', ['name' => 'workflow.instance.started']);
    }

    public function test_wf004_approving_records_the_decision_and_runs_the_next_step(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['mgr', 'approval', 'Manager', ['assignee' => ['type' => 'line_manager']]],
            ['fin', 'approval', 'Finance', ['assignee' => ['type' => 'user', 'value' => [$this->finance->id]]]],
            ['ok', 'end', 'Approved', ['outcome' => 'completed']], ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'mgr'], ['mgr', 'fin', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']], ['fin', 'ok', ['outcome' => 'approved']], ['fin', 'no', ['outcome' => 'rejected']]]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);

        $this->decide($this->manager, $this->openTask($i, 'mgr'), 'approved', 'Fine');
        $mgr = WorkflowTask::where('instance_id', $i->id)->where('node_key', 'mgr')->first();
        $this->assertSame(['approved', $this->manager->id, 'completed'], [$mgr->decision, $mgr->decided_by, $mgr->status]);
        $this->assertSame($this->finance->id, $this->openTask($i, 'fin')->assignee_id);
        $audit = WorkflowAuditEvent::where('action', 'task.decided')->where('instance_id', $i->id)->first();
        $this->assertSame(1, $audit->version, 'The decision records the workflow version (§8.3).');

        $this->decide($this->finance, $this->openTask($i, 'fin'), 'approved');
        $this->assertSame(['completed', 'approved'], [$i->fresh()->status, $i->fresh()->outcome]);
        $this->assertDatabaseHas('workflow_events', ['name' => 'workflow.instance.completed']);
    }

    public function test_wf005_rejection_needs_a_reason_and_follows_the_rejection_path(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $i = $this->startAs($this->requester, $d);
        $task = $this->openTask($i, 'mgr');
        try {
            $this->decide($this->manager, $task, 'rejected', '');
            $this->fail('A rejection without a reason should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        $this->decide($this->manager, $task->fresh(), 'rejected', 'Over budget for this quarter');
        $this->assertSame(['rejected', 'rejected'], [$i->fresh()->status, $i->fresh()->outcome]);
        $this->assertSame('Over budget for this quarter', $task->fresh()->comment);
        $this->assertDatabaseHas('workflow_comments', ['instance_id' => $i->id, 'body' => 'Rejected: Over budget for this quarter']);
    }

    public function test_wf006_self_approval_is_refused_and_audited_even_through_a_delegation(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $i = $this->startAs($this->requester, $d);
        // The manager delegates their approvals to the requester, who then tries to approve their own request.
        WorkflowDelegation::create(['from_user_id' => $this->manager->id, 'to_user_id' => $this->requester->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        try {
            $this->decide($this->requester, $this->openTask($i, 'mgr'), 'approved');
            $this->fail('Self-approval should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('your own request', $e->getMessage());
        }
        $this->assertSame(1, WorkflowAuditEvent::where('action', 'task.self_approval')->where('outcome', 'denied')->count());
        $this->assertSame('running', $i->fresh()->status);

        // The requester as the only possible approver is excluded at assignment, so the step goes to an exception.
        $d2 = $this->published($this->simpleApprovalGraph(['assignee' => ['type' => 'user', 'value' => [$this->requester->id]]]));
        $i2 = $this->startAs($this->requester, $d2);
        $t2 = $this->openTask($i2);
        $this->assertSame('exception', $t2->type);
        $this->assertSame($this->admin->id, $t2->assignee_id, 'Falls back to the process owner.');
    }

    public function test_wf007_only_the_eligible_branch_runs_and_the_decision_is_recorded(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['amount', 'decision', 'Amount band'],
            ['mgr', 'approval', 'Manager', ['assignee' => ['type' => 'line_manager']]],
            ['fin', 'approval', 'Finance', ['assignee' => ['type' => 'user', 'value' => [$this->finance->id]]]],
            ['ok', 'end', 'Approved'], ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'amount'],
            ['amount', 'fin', ['condition' => ['all' => [['field' => 'amount', 'operator' => 'gt', 'value' => 50000], ['field' => 'currency', 'operator' => 'eq', 'value' => 'ZAR']]]]],
            ['amount', 'mgr', ['is_default' => true]],
            ['mgr', 'ok', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']], ['fin', 'ok', ['outcome' => 'approved']], ['fin', 'no', ['outcome' => 'rejected']]]);
        $d = $this->published($graph);

        $small = $this->startAs($this->requester, $d, ['amount' => 1200, 'currency' => 'ZAR']);
        $this->assertSame(['mgr'], WorkflowTask::where('instance_id', $small->id)->pluck('node_key')->all());
        $big = $this->startAs($this->requester, $d, ['amount' => '75000.00', 'currency' => 'ZAR']);
        $this->assertSame(['fin'], WorkflowTask::where('instance_id', $big->id)->pluck('node_key')->all());
        $this->assertFalse(WorkflowNodeRun::where('instance_id', $big->id)->where('node_key', 'mgr')->exists(), 'The ineligible route never ran.');
        $route = WorkflowAuditEvent::where('action', 'route.decided')->where('instance_id', $big->id)->first();
        $this->assertTrue($route->context['evaluated'][0]['result']);
        $this->assertStringContainsString('amount is greater than 50000', $route->context['evaluated'][0]['condition']);
    }

    public function test_wf008_parallel_branches_run_and_the_join_follows_its_rule(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['split', 'parallel', 'Reviews'],
            ['legal', 'approval', 'Legal', ['assignee' => ['type' => 'user', 'value' => [$this->finance->id]]]],
            ['fin', 'approval', 'Finance', ['assignee' => ['type' => 'line_manager']]],
            ['join', 'join', 'Both done', ['mode' => 'all']], ['after', 'user_task', 'Execute', ['assignee' => ['type' => 'requester']]],
            ['ok', 'end', 'Done'], ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'split'], ['split', 'legal'], ['split', 'fin'],
            ['legal', 'join', ['outcome' => 'approved']], ['legal', 'no', ['outcome' => 'rejected']],
            ['fin', 'join', ['outcome' => 'approved']], ['fin', 'no', ['outcome' => 'rejected']],
            ['join', 'after'], ['after', 'ok']]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $this->assertEqualsCanonicalizing(['legal', 'fin'], WorkflowTask::where('instance_id', $i->id)->where('status', 'open')->pluck('node_key')->all());

        $this->decide($this->finance, $this->openTask($i, 'legal'), 'approved');
        $this->assertFalse(WorkflowTask::where('instance_id', $i->id)->where('node_key', 'after')->exists(), 'The join waits for every branch.');
        $this->decide($this->manager, $this->openTask($i, 'fin'), 'approved');
        $this->assertSame(1, WorkflowTask::where('instance_id', $i->id)->where('node_key', 'after')->count(), 'The step after the join ran exactly once.');
        $this->assertSame(1, WorkflowNodeRun::where('instance_id', $i->id)->where('node_key', 'join')->count());
    }

    public function test_quorum_approval_and_any_join_with_cancellation(): void
    {
        $third = $this->user('third', []);
        $graph = $this->graph([
            ['start', 'start'],
            ['board', 'approval', 'Committee', ['assignee' => ['type' => 'user', 'value' => [$this->manager->id, $this->finance->id, $third->id]], 'mode' => 'quorum', 'quorum' => 2]],
            ['ok', 'end', 'Approved'], ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'board'], ['board', 'ok', ['outcome' => 'approved']], ['board', 'no', ['outcome' => 'rejected']]]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $this->assertSame(3, WorkflowTask::where('instance_id', $i->id)->count());
        $this->decide($this->manager, $this->openTask($i, null, $this->manager), 'approved');
        $this->assertSame('running', $i->fresh()->status);
        $this->decide($third, $this->openTask($i, null, $third), 'approved');
        $this->assertSame('completed', $i->fresh()->status);
        $this->assertSame('cancelled', WorkflowTask::where('instance_id', $i->id)->where('assignee_id', $this->finance->id)->value('status'), 'The third vote is no longer needed.');
    }

    public function test_return_for_correction_loops_back_and_runs_the_step_again(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['mgr', 'approval', 'Manager', ['assignee' => ['type' => 'line_manager'], 'allow_return' => true]],
            ['fix', 'user_task', 'Correct it', ['assignee' => ['type' => 'requester']]],
            ['ok', 'end', 'Approved'], ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'mgr'], ['mgr', 'ok', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']], ['mgr', 'fix', ['outcome' => 'returned']], ['fix', 'mgr']]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $this->decide($this->manager, $this->openTask($i, 'mgr'), 'returned', 'Attach the quote');
        $this->decide($this->requester, $this->openTask($i, 'fix'), 'completed', 'Attached');
        $this->assertSame(2, WorkflowNodeRun::where('instance_id', $i->id)->where('node_key', 'mgr')->max('activation'));
        $this->decide($this->manager, $this->openTask($i, 'mgr'), 'approved');
        $this->assertSame('completed', $i->fresh()->status);
    }

    public function test_wf009_wf010_sla_warns_then_escalates_exactly_once(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-16 09:00', 'Africa/Johannesburg')); // a Monday, 09:00 local
        $d = $this->published($this->simpleApprovalGraph(['sla' => ['hours' => 4, 'business_hours' => true, 'warn_percent' => 50, 'escalation' => 'reassign_manager']]));
        $boss = $this->user('boss', []);
        $this->setManager($this->manager, $boss);
        $i = $this->startAs($this->requester, $d);
        $task = $this->openTask($i, 'mgr');
        $this->assertSame('13:00', $task->due_at->setTimezone('Africa/Johannesburg')->format('H:i'));

        $this->travel(150)->minutes();
        app(TickService::class)->run($this->company->id);
        $this->assertSame('warning', $task->fresh()->sla_state);
        $this->assertDatabaseHas('workflow_events', ['name' => 'workflow.sla.warning']);

        $this->travel(4)->hours();
        app(TickService::class)->run($this->company->id);
        app(TickService::class)->run($this->company->id);
        $task = $task->fresh();
        $this->assertSame(['breached', $boss->id], [$task->sla_state, $task->assignee_id], 'Reassigned to the assignee\'s manager.');
        $this->assertSame(1, WorkflowAuditEvent::where('action', 'sla.breached')->count(), 'Escalated once.');
        $this->assertSame(1, $task->history()->where('action', 'escalated')->count());
    }

    public function test_business_hours_skip_nights_weekends_and_public_holidays(): void
    {
        $clock = app(\App\Modules\Workflow\Services\WorkingTime::class);
        $friday = \Carbon\CarbonImmutable::parse('2026-12-24 15:00', 'Africa/Johannesburg'); // Thursday before Christmas
        $due = $clock->dueAt($friday, 4, true)->setTimezone('Africa/Johannesburg');
        // 2h on the 24th (15:00–17:00); the 25th is a holiday, the 26th (Day of Goodwill) a Saturday, the 27th a Sunday → Monday 28th 10:00.
        $this->assertSame('2026-12-28 10:00', $due->format('Y-m-d H:i'));
        $this->assertSame('2027-01-04 10:00', $clock->dueAt(\Carbon\CarbonImmutable::parse('2026-12-31 15:00', 'Africa/Johannesburg'), 4, true)->setTimezone('Africa/Johannesburg')->format('Y-m-d H:i'), 'New Year\'s Day and the weekend are skipped.');
        $this->assertSame(240, $clock->minutesBetween($friday, $due, true));
    }

    public function test_wf011_a_duplicate_start_does_not_create_a_second_request(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $a = $this->startAs($this->requester, $d, [], 'event-123');
        $b = $this->startAs($this->requester, $d, [], 'event-123');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, WorkflowInstance::where('definition_id', $d->id)->count());
        $this->assertSame(1, WorkflowTask::where('instance_id', $a->id)->count());
    }

    public function test_wf012_a_failing_action_is_retried_with_backoff_then_recorded_as_failed(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['act', 'action', 'Set reserved field', ['action' => 'set_value', 'input' => ['field' => 'record', 'value' => 'x'], 'max_attempts' => 2]],
            ['end', 'end'],
        ], [['start', 'act'], ['act', 'end']]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $run = WorkflowActionRun::where('instance_id', $i->id)->first();
        $this->assertSame(['pending', 1], [$run->status, $run->attempts]);
        $this->assertTrue($run->next_attempt_at->gt(now()));

        $this->travel(3)->minutes();
        app(TickService::class)->run($this->company->id);
        $run = $run->fresh();
        $this->assertSame(['failed', 2], [$run->status, $run->attempts]);
        $this->assertSame('running', $i->fresh()->status, 'Not reported as completed: the action never succeeded.');
        $this->assertStringContainsString('failed after 2 attempt', $i->fresh()->error);
        $this->assertDatabaseHas('workflow_events', ['name' => 'workflow.action.failed']);
    }

    public function test_wf013_waiting_work_survives_a_restart_and_resumes_from_the_database(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['wait', 'delay', 'Cooling-off', ['hours' => 2]], ['task', 'user_task', 'Follow up', ['assignee' => ['type' => 'requester']]], ['end', 'end'],
        ], [['start', 'wait'], ['wait', 'task'], ['task', 'end']]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $this->assertSame(1, WorkflowTimer::where('instance_id', $i->id)->where('kind', 'delay')->where('status', 'pending')->count());

        // A "restart": forget every resolved service; only the database remains.
        foreach ([\App\Modules\Workflow\Services\WorkflowRunner::class, TickService::class, \App\Modules\Workflow\Services\WorkflowEvents::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $this->app->forgetScopedInstances();
        $this->enterTenant();
        $this->travel(3)->hours();
        app(TickService::class)->run($this->company->id);
        $this->assertSame(1, WorkflowTask::where('instance_id', $i->id)->where('node_key', 'task')->count());
        app(TickService::class)->run($this->company->id);
        $this->assertSame(1, WorkflowTask::where('instance_id', $i->id)->where('node_key', 'task')->count(), 'A second tick does not repeat it.');
    }

    public function test_wf014_running_requests_keep_their_version_until_explicitly_migrated(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $old = $this->startAs($this->requester, $d);
        $graph = $d->currentVersion->graph;
        $graph['nodes'][1]['config']['assignee'] = ['type' => 'user', 'value' => [$this->finance->id]];
        $this->actingAs($this->admin);
        app(DefinitionService::class)->saveDraft($d, $graph, 'Finance approves');
        app(DefinitionService::class)->submit($d->refresh(), $this->admin, 'Finance approves');
        $new = $this->startAs($this->requester, $d->refresh());
        $this->assertNotSame($old->version_id, $new->version_id);
        $this->assertSame($this->manager->id, $this->openTask($old)->assignee_id, 'The running request keeps the old routing.');
        $this->assertSame($this->finance->id, $this->openTask($new)->assignee_id);

        $this->actingAs($this->admin);
        $result = app(DefinitionService::class)->migrate($d->refresh(), [$old->uuid], $this->admin, 'Move to the new matrix');
        $this->assertSame([$old->reference], $result['moved']);
        $this->assertSame($d->current_version_id, $old->fresh()->version_id);
        $this->assertSame(1, WorkflowTask::where('instance_id', $old->id)->count(), 'Migration keeps existing tasks and history.');
    }

    public function test_wf016_a_deactivated_assignee_s_tasks_go_to_their_manager(): void
    {
        $boss = $this->user('boss', []);
        $this->setManager($this->manager, $boss);
        $d = $this->published($this->simpleApprovalGraph());
        $i = $this->startAs($this->requester, $d);
        $this->manager->forceFill(['status' => 0])->save();
        $task = $this->openTask($i, 'mgr');
        $this->assertSame($boss->id, $task->assignee_id);
        $this->assertSame(1, $task->history()->where('action', 'reassigned')->where('reason', 'Assignee deactivated')->count());
    }

    public function test_wf019_an_unpermitted_material_action_is_blocked_at_publication_and_at_run_time(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['mgr', 'approval', 'Manager', ['assignee' => ['type' => 'line_manager']]],
            ['mark', 'action', 'Approve the record', ['action' => 'mark_record', 'input' => ['outcome' => 'approved']]],
            ['ok', 'end'], ['no', 'end', 'No', ['outcome' => 'rejected']],
        ], [['start', 'mgr'], ['mgr', 'mark', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']], ['mark', 'ok']]);
        $this->settings(['publication_requires_review' => false]);
        $this->actingAs($this->admin);
        $d = app(DefinitionService::class)->create(['name' => 'Material', 'process_owner_id' => $this->admin->id], $graph);
        try {
            app(DefinitionService::class)->submit($d, $this->admin, 'x');
            $this->fail('Should be blocked.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('has not permitted', $e->getMessage());
        }

        // Permitted at publication, then withdrawn: the run-time check still blocks it.
        $this->settings(['permitted_actions' => ['mark_record'], 'publication_requires_review' => false]);
        app(DefinitionService::class)->submit($d->refresh(), $this->admin, 'x');
        $i = $this->startAs($this->requester, $d->refresh());
        $this->settings(['permitted_actions' => []]);
        $this->decide($this->manager, $this->openTask($i, 'mgr'), 'approved');
        $this->assertSame(1, WorkflowAuditEvent::where('action', 'action.blocked')->where('outcome', 'denied')->count());
        $this->assertSame('exception', $this->openTask($i)->type);
        $this->assertSame(0, WorkflowActionRun::where('instance_id', $i->id)->where('action_key', 'mark_record')->count());
    }

    public function test_material_action_must_sit_behind_an_approval(): void
    {
        $this->settings(['permitted_actions' => ['mark_record']]);
        $graph = $this->graph([['start', 'start'], ['mark', 'action', 'Mark', ['action' => 'mark_record', 'input' => ['outcome' => 'approved']]], ['end', 'end']],
            [['start', 'mark'], ['mark', 'end']]);
        $this->actingAs($this->admin);
        $d = app(DefinitionService::class)->create(['name' => 'Ungated'], $graph);
        $this->assertStringContainsString('without an approval', collect(app(\App\Modules\Workflow\Services\GraphValidator::class)->validate($graph, $d))->pluck('message')->implode(' '));
    }

    public function test_wf020_cancelling_stops_every_parallel_branch(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['split', 'parallel'],
            ['a', 'user_task', 'A', ['assignee' => ['type' => 'user', 'value' => [$this->finance->id]]]],
            ['b', 'user_task', 'B', ['assignee' => ['type' => 'line_manager']]],
            ['join', 'join', 'Join', ['mode' => 'all']], ['act', 'action', 'Note', ['action' => 'add_comment', 'input' => ['message' => 'after join']]], ['end', 'end'],
        ], [['start', 'split'], ['split', 'a'], ['split', 'b'], ['a', 'join'], ['b', 'join'], ['join', 'act'], ['act', 'end']]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $this->actingAs($this->admin);
        DB::transaction(fn () => app(WorkflowRunner::class)->cancel(WorkflowInstance::lockForUpdate()->find($i->id), $this->admin->id, 'Requester withdrew'));
        $this->assertSame('cancelled', $i->fresh()->status);
        $this->assertSame(0, WorkflowTask::where('instance_id', $i->id)->where('status', 'open')->count());
        try {
            $this->decide($this->finance, WorkflowTask::where('instance_id', $i->id)->where('node_key', 'a')->first(), 'completed');
            $this->fail('A cancelled request takes no decisions.');
        } catch (WorkflowException) {
        }
        $this->assertSame(0, WorkflowActionRun::where('instance_id', $i->id)->count(), 'Nothing after the join ran.');
    }

    public function test_queue_tasks_are_claimed_and_delegations_act_on_behalf(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['triage', 'user_task', 'Triage', ['assignee' => ['type' => 'user', 'value' => [$this->manager->id, $this->finance->id]]]],
            ['appr', 'approval', 'Approve', ['assignee' => ['type' => 'user', 'value' => [$this->manager->id]]]],
            ['ok', 'end'], ['no', 'end', 'No', ['outcome' => 'rejected']],
        ], [['start', 'triage'], ['triage', 'appr'], ['appr', 'ok', ['outcome' => 'approved']], ['appr', 'no', ['outcome' => 'rejected']]]);
        $d = $this->published($graph);
        $i = $this->startAs($this->requester, $d);
        $task = $this->openTask($i, 'triage');
        $this->assertNull($task->assignee_id);
        try {
            $this->decide($this->finance, $task, 'completed');
            $this->fail('A queue task is claimed first.');
        } catch (WorkflowException) {
        }
        app(TaskService::class)->claim($task, $this->finance);
        try {
            app(TaskService::class)->claim($task->fresh(), $this->manager);
            $this->fail('Already claimed.');
        } catch (WorkflowException) {
        }
        $this->decide($this->finance, $task->fresh(), 'completed');

        // The manager is away; the finance user acts on their behalf (but not in their own name).
        WorkflowDelegation::create(['from_user_id' => $this->manager->id, 'to_user_id' => $this->finance->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3)]);
        $this->decide($this->finance, $this->openTask($i, 'appr'), 'approved', 'Approved while the manager is on leave');
        $appr = WorkflowTask::where('instance_id', $i->id)->where('node_key', 'appr')->first();
        $this->assertSame([$this->finance->id, $this->manager->id], [$appr->decided_by, $appr->decided_on_behalf_of]);
        $this->assertSame('completed', $i->fresh()->status);
    }

    public function test_information_requests_pause_the_sla_and_move_the_deadline(): void
    {
        $d = $this->published($this->simpleApprovalGraph(['sla' => ['hours' => 8, 'business_hours' => false]]));
        $i = $this->startAs($this->requester, $d);
        $task = $this->openTask($i, 'mgr');
        $due = $task->due_at;
        $this->decide($this->manager, $task, 'info_requested', 'Which cost centre?');
        $this->assertSame(['open', 'paused'], [$task->fresh()->status, $task->fresh()->sla_state]);
        $this->travel(2)->hours();
        $this->actingAs($this->requester);
        app(TaskService::class)->respond($i, $this->requester, 'CC-410');
        $task = $task->fresh();
        $this->assertSame('on_track', $task->sla_state);
        $this->assertEqualsWithDelta($due->addHours(2)->getTimestamp(), $task->due_at->getTimestamp(), 2);
        $this->decide($this->manager, $task, 'approved');
        $this->assertSame('completed', $i->fresh()->status);
    }

    public function test_sub_workflows_run_and_resume_their_parent(): void
    {
        $child = $this->published($this->graph([['start', 'start'], ['t', 'user_task', 'Child task', ['assignee' => ['type' => 'requester']]], ['end', 'end']], [['start', 't'], ['t', 'end']]), ['key' => 'child_flow', 'name' => 'Child']);
        $parent = $this->published($this->graph([
            ['start', 'start'], ['sub', 'subworkflow', 'Run child', ['definition_key' => $child->key, 'wait' => true]], ['ok', 'end'], ['no', 'end', 'No', ['outcome' => 'rejected']],
        ], [['start', 'sub'], ['sub', 'ok', ['outcome' => 'completed']], ['sub', 'no', ['outcome' => 'rejected']]]));
        $i = $this->startAs($this->requester, $parent);
        $kid = WorkflowInstance::where('parent_instance_id', $i->id)->firstOrFail();
        $this->decide($this->requester, $this->openTask($kid), 'completed');
        $this->assertSame('completed', $kid->fresh()->status);
        $this->assertSame('completed', $i->fresh()->status);
    }

    public function test_publication_review_needs_a_second_person(): void
    {
        $this->settings(['publication_requires_review' => true]);
        $this->actingAs($this->admin);
        $defs = app(DefinitionService::class);
        $d = $defs->create(['name' => 'Reviewed', 'process_owner_id' => $this->admin->id], $this->simpleApprovalGraph());
        $v = $defs->submit($d, $this->admin, 'First');
        $this->assertSame('in_review', $v->status);
        try {
            $defs->review($d, $this->admin, true, 'Looks good');
            $this->fail('The author cannot review their own version.');
        } catch (WorkflowException) {
        }
        $this->actingAs($this->publisher);
        $defs->review($d, $this->publisher, true, 'Checked the routing');
        $this->assertSame('published', $d->fresh()->status);
        $this->assertSame($this->publisher->id, $v->fresh()->reviewed_by);
    }

    public function test_the_audit_trail_is_chained_and_append_only(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $i = $this->startAs($this->requester, $d);
        $this->decide($this->manager, $this->openTask($i), 'approved');
        $this->assertNull(app(WorkflowAudit::class)->verifyChain($this->company->id));
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('workflow_audit_events')->where('company_id', $this->company->id)->limit(1)->update(['reason' => 'tampered']);
    }
}
