<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTimer;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\GraphValidator;
use App\Modules\Workflow\Services\TickService;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Support\ConditionChecks;

/**
 * The engine steps the farm's processes are built from: one child per item, waiting for an outside
 * event or for a condition until a deadline, a step that repeats on a schedule, an event that
 * interrupts whatever is under way, and an end that closes only its own branch.
 */
class WorkflowEventNodesTest extends WorkflowTestCase
{
    private function tick(): array
    {
        return app(TickService::class)->run($this->company->id);
    }

    private function child(string $key): void
    {
        $this->published($this->graph([
            ['start', 'start'], ['work', 'user_task', 'Do {{item.name}}', ['assignee' => ['type' => 'requester']]], ['end', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'work'], ['work', 'end']]), ['key' => $key, 'name' => 'Child '.$key]);
    }

    public function test_one_child_per_item_runs_side_by_side_and_the_parent_waits_for_all(): void
    {
        $this->child('wf_child');
        $parent = $this->published($this->graph([
            ['start', 'start'], ['each', 'multi_instance', 'Each item', ['definition_key' => 'wf_child', 'collection' => 'items', 'item_as' => 'item', 'pass_data' => true,
                'wait' => true, 'results_as' => 'results']],
            ['end', 'end', 'All done', ['outcome' => 'completed']],
        ], [['start', 'each'], ['each', 'end']]));

        $i = $this->startAs($this->requester, $parent, ['items' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']], 'farm' => 'Boplaas']);
        $children = WorkflowInstance::query()->where('parent_instance_id', $i->id)->orderBy('id')->get();
        $this->assertCount(3, $children);
        $this->assertSame(['A', 'B', 'C'], $children->pluck('data.item.name')->all());
        $this->assertSame('Boplaas', $children[1]->data['farm'], 'the parent\'s values go to each child');
        $this->assertSame('running', $i->fresh()->status);

        foreach ($children as $n => $c) {
            $this->decide($this->requester, $this->openTask($c), 'completed');
            $this->assertSame($n < 2 ? 'running' : 'completed', $i->fresh()->status, 'the parent waits for every child');
        }
        $this->assertCount(3, $i->fresh()->data['results']);
        // Starting twice from the same step never doubles the children.
        $this->assertSame(3, WorkflowInstance::query()->where('parent_instance_id', $i->id)->count());
    }

    public function test_a_waiting_step_continues_only_on_its_own_correlated_event_and_times_out_otherwise(): void
    {
        $d = $this->published($this->graph([
            ['start', 'start'], ['wait', 'wait_event', 'Wait for the lab', ['event' => 'lab.result', 'correlate_field' => 'sample', 'correlate_key' => 'sample', 'store_as' => 'lab', 'timeout_hours' => 48]],
            ['use', 'user_task', 'Use the result', ['assignee' => ['type' => 'requester']]], ['chase', 'user_task', 'Chase the lab', ['assignee' => ['type' => 'requester']]],
            ['end', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'wait'], ['wait', 'use', ['outcome' => 'received']], ['wait', 'chase', ['outcome' => 'timeout']], ['use', 'end'], ['chase', 'end']]));

        $a = $this->startAs($this->requester, $d, ['sample' => 'S-1']);
        $b = $this->startAs($this->requester, $d, ['sample' => 'S-2']);
        $r = app(WorkflowRunner::class)->signal($this->company->id, 'lab.result', ['sample' => 'S-1', 'ph' => 5.1]);
        $this->assertSame(1, $r['resumed']);
        $this->assertSame(5.1, $a->fresh()->data['lab']['ph']);
        $this->assertSame('use', $this->openTask($a)->node_key);
        $this->assertSame(0, WorkflowTask::query()->where('instance_id', $b->id)->count(), 'S-2 is still waiting');

        $this->travel(49)->hours();
        $this->tick();
        $this->assertSame('chase', $this->openTask($b)->node_key, 'no result in time: the timeout path');
    }

    public function test_waiting_for_a_condition_checks_on_a_schedule_and_takes_the_deadline_path(): void
    {
        ConditionChecks::register('test.level', 'Test level', [['key' => 'min', 'label' => 'Min', 'type' => 'number']],
            fn (WorkflowInstance $i, array $p) => ['met' => (float) cache('wf-test-level', 0) >= (float) $p['min'], 'value' => cache('wf-test-level', 0)]);
        $d = $this->published($this->graph([
            ['start', 'start'], ['wait', 'wait_condition', 'Wait for the level', ['check' => 'test.level', 'params' => ['min' => 25], 'deadline_field' => 'closes_on', 'poll_minutes' => 60]],
            ['go', 'user_task', 'Go', ['assignee' => ['type' => 'requester']]], ['late', 'user_task', 'Window closed', ['assignee' => ['type' => 'requester']]],
            ['end', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'wait'], ['wait', 'go', ['outcome' => 'met']], ['wait', 'late', ['outcome' => 'timeout']], ['go', 'end'], ['late', 'end']]));

        cache(['wf-test-level' => 10]);
        $met = $this->startAs($this->requester, $d, ['closes_on' => today()->addDays(3)->toDateString()]);
        $missed = $this->startAs($this->requester, $d, ['closes_on' => today()->addDay()->toDateString()]);
        $this->assertSame(0, WorkflowTask::query()->whereIn('instance_id', [$met->id, $missed->id])->count());

        $this->travel(2)->hours();
        $this->tick();
        $this->assertSame(0, WorkflowTask::query()->whereIn('instance_id', [$met->id, $missed->id])->count(), 'still below the level');

        $this->travel(2)->days();
        $this->tick(); // the second request's deadline passed first
        $this->assertSame('late', $this->openTask($missed)->node_key);
        cache(['wf-test-level' => 30]);
        $this->travel(2)->hours();
        $this->tick();
        $this->assertSame('go', $this->openTask($met)->node_key);
        cache()->forget('wf-test-level');
    }

    public function test_a_recurring_step_repeats_until_its_stop_condition(): void
    {
        $d = $this->published($this->graph([
            ['start', 'start'], ['every', 'recurring', 'Every 14 days', ['every_days' => 14, 'node' => 'look', 'stop_when' => ['field' => 'finished', 'operator' => 'true']]],
            ['look', 'user_task', 'Look', ['assignee' => ['type' => 'requester']]], ['look_end', 'end', 'Looked', ['outcome' => 'completed', 'scope' => 'branch']],
            ['main', 'user_task', 'Finish', ['assignee' => ['type' => 'requester'], 'form_id' => null]], ['end', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'every'], ['every', 'main'], ['look', 'look_end'], ['main', 'end']]));

        $i = $this->startAs($this->requester, $d);
        $this->assertSame(['main'], WorkflowTask::query()->where('instance_id', $i->id)->where('status', 'open')->pluck('node_key')->all());
        foreach ([1, 2] as $round) {
            $this->travel(14)->days();
            $this->tick();
            $look = $this->openTask($i, 'look');
            $this->decide($this->requester, $look, 'completed');
            $this->assertSame('running', $i->fresh()->status, 'a finished round closes only its branch');
        }
        $this->assertSame(2, WorkflowTask::query()->where('instance_id', $i->id)->where('node_key', 'look')->count());
        $this->assertSame(1, WorkflowTimer::query()->where('instance_id', $i->id)->where('kind', 'recurring')->where('status', 'pending')->count(), 'the next round is booked');

        $this->decide($this->requester, $this->openTask($i, 'main'), 'completed');
        $this->assertSame('completed', $i->fresh()->status);
        $this->travel(14)->days();
        $this->tick();
        $this->assertSame(2, WorkflowTask::query()->where('instance_id', $i->id)->where('node_key', 'look')->count(), 'a closed request books no more rounds');
    }

    public function test_an_interrupting_event_stops_open_work_and_runs_the_handler(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['grow', 'user_task', 'Grow', ['assignee' => ['type' => 'requester']]],
            ['assess', 'user_task', 'Assess damage', ['assignee' => ['type' => 'requester']]], ['end', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'grow'], ['grow', 'end'], ['assess', 'grow']]);
        $graph['events'] = [['event' => 'hail', 'correlate' => ['event_key' => 'field', 'data_field' => 'field'], 'to' => 'assess', 'interrupting' => true, 'store_as' => 'damage']];
        $d = $this->published($graph);

        $hit = $this->startAs($this->requester, $d, ['field' => 'F1']);
        $spared = $this->startAs($this->requester, $d, ['field' => 'F2']);
        $grow = $this->openTask($hit);
        $r = app(WorkflowRunner::class)->signal($this->company->id, 'hail', ['field' => 'F1', 'size_mm' => 20]);

        $this->assertSame(1, $r['interrupted']);
        $this->assertSame('cancelled', $grow->fresh()->status);
        $this->assertSame('assess', $this->openTask($hit)->node_key);
        $this->assertSame(20, $hit->fresh()->data['damage']['size_mm']);
        $this->assertSame('grow', $this->openTask($spared)->node_key, 'another field is not touched');
        // After the handler the work goes on.
        $this->decide($this->requester, $this->openTask($hit), 'completed');
        $this->assertSame('grow', $this->openTask($hit)->node_key);
    }

    public function test_the_validator_checks_the_new_steps(): void
    {
        $graph = $this->graph([
            ['start', 'start'], ['each', 'multi_instance', 'Each', ['definition_key' => 'nope', 'collection' => '']],
            ['wait', 'wait_event', 'Wait', ['timeout_hours' => 5]], ['cond', 'wait_condition', 'Until', ['check' => 'no.such.check']],
            ['every', 'recurring', 'Every', ['every_days' => 0, 'node' => 'start']], ['end', 'end', 'Done', ['outcome' => 'completed']],
        ], [['start', 'each'], ['each', 'wait'], ['wait', 'cond', ['outcome' => 'received']], ['cond', 'every', ['outcome' => 'met']], ['every', 'end']]);
        $graph['events'] = [['event' => '', 'to' => 'nowhere']];
        $this->actingAs($this->admin);
        $draft = app(DefinitionService::class)->create(['name' => 'Draft checks', 'category' => 'Test']);
        $problems = implode("\n", array_column(app(GraphValidator::class)->validate($graph, $draft), 'message'));

        foreach (['published workflow', 'which list', 'timeout', 'which event', 'check that is not available', 'no interval', 'step to repeat', 'interruption'] as $word) {
            $this->assertStringContainsStringIgnoringCase($word, $problems);
        }
    }
}
