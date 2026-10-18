<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowComment;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNodeRun;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTaskAssignment;
use App\Modules\Workflow\Models\WorkflowTimer;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Support\ActionCatalog;
use App\Modules\Workflow\Support\Conditions;
use App\Modules\Workflow\Support\NodeTypes;
use App\Modules\Workflow\Support\ProviderRegistry;
use App\Modules\Workflow\Support\Template;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The execution engine (PRS §19).
 *
 * Each state change commits in the request that causes it: starting a request or deciding a task
 * advances the graph synchronously through every step that does not wait, then stops at the next
 * person, timer, join or retry. What waits is a row (a task, a timer, an action run), so a restart
 * resumes from the database and workflow:tick picks up anything due. The instance row is locked for
 * the whole advance, and a node run is unique per (instance, node, activation), so concurrent
 * decisions and re-delivered ticks cannot run a step twice.
 */
class WorkflowRunner
{
    private const MAX_STEPS_PER_ADVANCE = 500;

    public function __construct(
        private readonly WorkflowAudit $audit,
        private readonly WorkflowEvents $events,
        private readonly AssigneeResolver $resolver,
        private readonly WorkingTime $clock,
        private readonly Notifier $notifier,
        private readonly WorkflowSettings $settings,
        private readonly ProviderRegistry $providers,
    ) {
    }

    // ── Starting ────────────────────────────────────────────────────────────

    /**
     * Start a request on the definition's published version.
     *
     * @param  array{type?:string, id?:string|int, ref?:string, title?:string}  $subject
     */
    public function start(WorkflowDefinition $definition, array $data, ?int $requesterId, array $subject = [], ?string $dedupeKey = null, ?int $parentInstanceId = null, ?string $title = null): WorkflowInstance
    {
        if ($definition->status !== 'published' || ! $definition->current_version_id) {
            throw new WorkflowException("\"{$definition->name}\" is not published, so it cannot be started.");
        }
        // §5: the same event delivered twice is the same request.
        if ($dedupeKey && ($existing = WorkflowInstance::query()->where('dedupe_key', $dedupeKey)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($definition, $data, $requesterId, $subject, $dedupeKey, $parentInstanceId, $title) {
            $version = WorkflowVersion::query()->findOrFail($definition->current_version_id);
            $requester = $requesterId ? \App\Core\User\Models\User::find($requesterId) : null;
            $data['requester'] = [
                'id' => $requesterId, 'name' => $requester ? $this->audit->nameOf($requester) : null,
                'department_id' => $this->resolver->departmentOf($requesterId), 'manager_id' => $this->resolver->managerOf($requesterId),
            ];
            if (! empty($subject['type']) && ! empty($subject['id']) && ($provider = $this->providers->get($subject['type']))) {
                $record = $provider->find((string) $subject['id']);
                if ($record) {
                    $data['record'] = $provider->snapshot($record);
                    $subject['ref'] ??= $provider->reference($record);
                    $title ??= $provider->title($record);
                }
            }

            $instance = WorkflowInstance::create([
                'company_id' => $definition->company_id,
                'definition_id' => $definition->id, 'version_id' => $version->id,
                'reference' => $this->nextReference($definition->company_id),
                'title' => mb_substr($title ?? ($definition->name . ($requester ? ' — ' . $data['requester']['name'] : '')), 0, 200),
                'status' => 'running',
                'subject_type' => $subject['type'] ?? null, 'subject_id' => isset($subject['id']) ? (string) $subject['id'] : null,
                'subject_ref' => $subject['ref'] ?? null,
                'requester_id' => $requesterId, 'priority' => $definition->default_priority ?: 'normal',
                'data' => $data, 'dedupe_key' => $dedupeKey, 'parent_instance_id' => $parentInstanceId,
                'started_at' => now(),
            ]);
            $instance->setRelation('definition', $definition);
            $instance->setRelation('version', $version);

            $this->audit->record('instance.started', $instance, ['instance' => $instance, 'version' => $version->version,
                'after' => ['reference' => $instance->reference, 'subject' => $subject['ref'] ?? null]]);
            $this->events->publish('workflow.instance.started', $this->eventPayload($instance));

            $start = collect($version->graph['nodes'] ?? [])->firstWhere('type', 'start');
            $this->run($instance, [[$start['key'], null]]);

            return $instance->refresh();
        });
    }

    // ── Advancing ───────────────────────────────────────────────────────────

    /**
     * Enter each [node, via edge] pair, then everything they lead to that does not wait.
     *
     * @param  list<array{0:string, 1:?string}>  $queue
     */
    public function run(WorkflowInstance $instance, array $queue): void
    {
        $version = $instance->relationLoaded('version') ? $instance->version : $instance->version()->first();
        $steps = 0;
        while ($queue && $instance->status === 'running') {
            if (++$steps > self::MAX_STEPS_PER_ADVANCE) {
                $this->fail($instance, 'The workflow looped more than ' . self::MAX_STEPS_PER_ADVANCE . ' steps without waiting; it was stopped.');

                return;
            }
            [$key, $via] = array_shift($queue);
            $node = $version->node($key);
            if (! $node) {
                $this->fail($instance, "The workflow refers to a step \"{$key}\" that its version does not contain.");

                return;
            }
            foreach ($this->enter($instance, $version, $node, $via) as $next) {
                $queue[] = $next;
            }
        }
    }

    /** @return list<array{0:string, 1:?string}> what to enter next */
    private function enter(WorkflowInstance $instance, WorkflowVersion $version, array $node, ?string $via): array
    {
        $type = $node['type'];
        $cfg = $node['config'] ?? [];

        if ($type === 'join') {
            return $this->arriveAtJoin($instance, $version, $node, $via);
        }

        $run = $this->openRun($instance, $node, $via);

        switch ($type) {
            case 'start':
                return $this->finish($instance, $version, $run, 'completed');

            case 'end':
                $this->closeRun($run, 'completed', $cfg['outcome'] ?? 'completed');
                $this->terminate($instance, $cfg['outcome'] ?? 'completed', null, null);

                return [];

            case 'decision':
                $data = $instance->data;
                $chosen = null;
                $evaluated = [];
                foreach ($version->outgoing($node['key']) as $e) {
                    if (! empty($e['is_default'])) {
                        continue;
                    }
                    $ok = Conditions::evaluate($e['condition'] ?? null, $data);
                    $evaluated[] = ['edge' => $e['key'] ?? null, 'condition' => Conditions::describe($e['condition'] ?? null), 'result' => $ok];
                    if ($ok) {
                        $chosen = $e;
                        break;
                    }
                }
                $chosen ??= collect($version->outgoing($node['key']))->first(fn ($e) => ! empty($e['is_default']));
                $run->detail = ['evaluated' => $evaluated, 'chosen' => $chosen['key'] ?? null, 'default' => ! empty($chosen['is_default'])];
                if (! $chosen) {
                    $this->closeRun($run, 'failed', 'no_route');

                    return $this->raiseException($instance, $node, "Decision \"{$node['name']}\" had no matching route and no default.");
                }
                // §18: which condition decided the route is part of the record.
                $this->audit->record('route.decided', $instance, ['instance' => $instance, 'version' => $version->version,
                    'context' => ['node' => $node['key'], 'evaluated' => $evaluated, 'chosen' => $chosen['key'] ?? null]]);
                $this->closeRun($run, 'completed', 'routed');

                return [[$chosen['to'], $chosen['key'] ?? null]];

            case 'parallel':
                $this->closeRun($run, 'completed', 'split');

                return array_map(fn ($e) => [$e['to'], $e['key'] ?? null], $version->outgoing($node['key']));

            case 'user_task':
            case 'approval':
            case 'review':
            case 'exception':
                $this->createTasks($instance, $version, $node, $run);

                return [];

            case 'delay':
                $fireAt = ! blank($cfg['until_field'] ?? null) && ($d = data_get($instance->data, $cfg['until_field']))
                    ? \Carbon\CarbonImmutable::parse($d)
                    : $this->clock->dueAt(now(), (float) ($cfg['hours'] ?? 0), ! empty($cfg['business_hours']), $instance->definition?->calendar_id ? \App\Modules\Workflow\Models\WorkflowCalendar::find($instance->definition->calendar_id) : null);
                WorkflowTimer::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'node_run_id' => $run->id, 'kind' => 'delay', 'fire_at' => $fireAt]);
                $run->status = 'waiting';
                $run->detail = ['until' => $fireAt->toIso8601String()];
                $run->save();

                return [];

            case 'notification':
                $to = $this->resolver->resolve($cfg['to'] ?? [], $instance);
                $sensitive = $this->sensitiveFields($instance);
                $this->notifier->send($to, 'notice', Template::render($cfg['subject'] ?? $node['name'], $instance->data, $sensitive),
                    Template::render($cfg['message'] ?? '', $instance->data, $sensitive), url('/workflow/instances/' . $instance->uuid), $instance, $node['key'] . ':' . $run->activation);
                $run->detail = ['recipients' => $to];

                return $this->finish($instance, $version, $run, 'completed');

            case 'action':
                return $this->startAction($instance, $version, $node, $run);

            case 'document':
                return $this->generateDocument($instance, $version, $node, $run);

            case 'subworkflow':
                return $this->startChild($instance, $version, $node, $run);
        }

        $this->closeRun($run, 'failed', 'unknown_type');

        return $this->raiseException($instance, $node, "Step \"{$node['name']}\" has an unknown type.");
    }

    /** Follow the edges out of a finished node. */
    private function finish(WorkflowInstance $instance, WorkflowVersion $version, WorkflowNodeRun $run, string $outcome): array
    {
        $this->closeRun($run, 'completed', $outcome);
        $node = $version->node($run->node_key);
        $outs = $version->outgoing($run->node_key);
        if (NodeTypes::routesByOutcome($node['type'])) {
            $matching = array_values(array_filter($outs, fn ($e) => ($e['outcome'] ?? null) === $outcome));
            if (! $matching && $outcome === 'completed' && count($outs) === 1 && empty($outs[0]['outcome'])) {
                $matching = $outs;
            }
            if (! $matching) {
                return $this->raiseException($instance, $node, "\"{$node['name']}\" finished as \"{$outcome}\" but has no route for that outcome.");
            }
            $outs = $matching;
        }

        return array_map(fn ($e) => [$e['to'], $e['key'] ?? null], $outs);
    }

    private function arriveAtJoin(WorkflowInstance $instance, WorkflowVersion $version, array $node, ?string $via): array
    {
        $cfg = $node['config'] ?? [];
        $incoming = count($version->incoming($node['key']));
        $needed = match ($cfg['mode'] ?? 'all') { 'any' => 1, 'quorum' => max(1, min($incoming, (int) ($cfg['quorum'] ?? 1))), default => $incoming };

        $run = WorkflowNodeRun::query()->where('instance_id', $instance->id)->where('node_key', $node['key'])->orderByDesc('activation')->lockForUpdate()->first();
        if ($run && $run->status === 'completed') {
            $arrived = $run->detail['arrived'] ?? [];
            if (! in_array($via, $arrived, true)) {
                // §9: a branch finishing after the join already continued must not run what follows again.
                $run->detail = array_merge($run->detail ?? [], ['late' => array_values(array_unique(array_merge($run->detail['late'] ?? [], [$via])))]);
                $run->save();

                return [];
            }
            $run = null; // the same branch arriving again is a new pass (e.g. after a return for correction)
        }
        if (! $run || $run->status !== 'waiting') {
            $run = $this->openRun($instance, $node, $via);
            $run->status = 'waiting';
            $run->detail = ['arrived' => [], 'needed' => $needed];
        }
        $detail = $run->detail ?? ['arrived' => []];
        $detail['arrived'] = array_values(array_unique(array_merge($detail['arrived'] ?? [], [$via])));
        $detail['needed'] = $needed;
        $run->detail = $detail;
        $run->save();

        if (count($detail['arrived']) < $needed) {
            return [];
        }
        if (! empty($cfg['cancel_remaining'])) {
            $this->cancelOpenWork($instance, 'The join continued without this branch.', exceptRunId: $run->id);
        }

        return $this->finish($instance, $version, $run, 'completed');
    }

    // ── Human tasks ─────────────────────────────────────────────────────────

    private function createTasks(WorkflowInstance $instance, WorkflowVersion $version, array $node, WorkflowNodeRun $run): void
    {
        $cfg = $node['config'] ?? [];
        $type = $node['type'];
        $spec = $cfg['assignee'] ?? ($type === 'exception' ? ['type' => 'process_owner'] : []);
        $people = $this->resolver->resolve($spec, $instance);
        $excluded = [];

        if ($type === 'approval') {
            // §3: nobody approves their own request unless both company policy and the step allow it.
            if (! $this->selfApprovalAllowed($instance, $cfg) && $instance->requester_id && in_array($instance->requester_id, $people, true)) {
                $people = array_values(array_diff($people, [$instance->requester_id]));
                $excluded[] = ['user' => $instance->requester_id, 'why' => 'requester'];
            }
            // procgov's rule: one person does not approve two levels of the same request.
            if (($cfg['distinct_approvers'] ?? true) !== false) {
                $already = WorkflowTask::query()->where('instance_id', $instance->id)->where('type', 'approval')->where('decision', 'approved')
                    ->pluck('decided_by')->map(fn ($v) => (int) $v)->all();
                foreach (array_intersect($people, $already) as $u) {
                    $excluded[] = ['user' => $u, 'why' => 'approved an earlier level'];
                }
                $people = array_values(array_diff($people, $already));
            }
        }

        $exception = null;
        if (! $people) {
            // §8.1: an unresolvable assignee goes to the configured exception route, never nowhere.
            $exception = 'No eligible assignee could be found (' . (NodeTypes::ASSIGNEE_TYPES[$spec['type'] ?? ''] ?? 'nobody configured') . ').';
            $people = $this->fallbackAssignees($instance);
        }

        $calendar = $instance->definition?->calendar_id ? \App\Modules\Workflow\Models\WorkflowCalendar::find($instance->definition->calendar_id) : null;
        $sla = $cfg['sla'] ?? (($h = $this->settings->get('default_sla_hours', $instance->company_id)) ? ['hours' => $h, 'business_hours' => true] : null);
        $due = $sla ? $this->clock->dueAt(now(), (float) $sla['hours'], ! empty($sla['business_hours']), $calendar) : null;

        $queue = $exception ? count($people) > 1 : (! empty($cfg['queue']) || (in_array($type, ['user_task', 'review', 'exception'], true) && count($people) > 1));
        $base = [
            'company_id' => $instance->company_id, 'instance_id' => $instance->id, 'node_run_id' => $run->id, 'node_key' => $node['key'],
            'type' => $exception ? 'exception' : $type,
            'name' => $exception ? 'Needs attention: ' . $node['name'] : $node['name'],
            'status' => 'open', 'priority' => $instance->priority, 'due_at' => $due, 'sla_state' => $due ? 'on_track' : 'none',
            'comment' => $exception,
        ];
        $tasks = [];
        if ($queue || $people === []) {
            $tasks[] = WorkflowTask::create($base + ['assignee_id' => null, 'candidates' => $people]);
        } else {
            foreach ($people as $userId) {
                $tasks[] = WorkflowTask::create($base + ['assignee_id' => $userId, 'original_assignee_id' => $userId, 'candidates' => []]);
            }
        }

        $run->status = 'waiting';
        $run->detail = array_filter(['assignees' => $people, 'excluded' => $excluded, 'queue' => $queue, 'exception' => $exception,
            'mode' => $type === 'approval' ? ($cfg['mode'] ?? 'any') : null, 'quorum' => $cfg['quorum'] ?? null], fn ($v) => $v !== null && $v !== []);
        $run->save();

        foreach ($tasks as $task) {
            if ($task->assignee_id) {
                WorkflowTaskAssignment::create(['company_id' => $instance->company_id, 'task_id' => $task->id, 'action' => 'assigned', 'to_user_id' => $task->assignee_id]);
            }
            if ($due) {
                $this->scheduleSla($task, $sla, $calendar);
            }
            $this->events->publish('workflow.task.assigned', $this->eventPayload($instance) + ['task' => $task->uuid, 'node' => $node['key']]);
        }
        $this->audit->record($exception ? 'task.unassignable' : 'task.created', $instance, ['instance' => $instance, 'version' => $version->version,
            'reason' => $exception, 'context' => ['node' => $node['key'], 'assignees' => $people, 'excluded' => $excluded, 'queue' => $queue]]);

        foreach ($tasks as $task) {
            $to = $task->assignee_id ? [$task->assignee_id] : $task->candidates;
            $this->notifier->send($to, $exception ? 'exception' : 'assigned',
                ($exception ? 'Needs attention: ' : ($type === 'approval' ? 'Approval needed: ' : 'New task: ')) . $node['name'],
                "{$instance->reference} — {$instance->title}" . ($due ? ' · due ' . $due->setTimezone('Africa/Johannesburg')->format('j M H:i') : ''),
                url('/workflow/tasks/' . $task->uuid), $instance, 'task:' . $task->id);
        }
    }

    /** @return list<int> */
    private function fallbackAssignees(WorkflowInstance $instance): array
    {
        $owner = $this->resolver->resolve(['type' => 'process_owner'], $instance);
        if ($owner && $this->settings->get('unassigned_policy', $instance->company_id) === 'process_owner') {
            return $owner;
        }

        // The exception queue: everyone in the company who manages workflows.
        $managers = DB::table('model_has_permissions as mp')->join('permissions as p', 'p.id', '=', 'mp.permission_id')
            ->where('p.name', \App\Modules\Workflow\Support\WorkflowPermissions::MANAGE)->where('mp.model_type', \App\Core\User\Models\User::class)->pluck('mp.model_id')
            ->merge(DB::table('model_has_roles as mr')->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.name', \App\Modules\Workflow\Support\WorkflowPermissions::MANAGE)
                ->where('mr.model_type', \App\Core\User\Models\User::class)->pluck('mr.model_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();

        return $this->resolver->active($managers, $instance->company_id) ?: $owner;
    }

    public function selfApprovalAllowed(WorkflowInstance $instance, array $cfg): bool
    {
        return ! empty($cfg['allow_self_approval']) && $this->settings->get('self_approval', $instance->company_id) === 'node_decides';
    }

    private function scheduleSla(WorkflowTask $task, array $sla, $calendar): void
    {
        $warnPercent = (int) ($sla['warn_percent'] ?? 75);
        $hours = (float) $sla['hours'];
        $warnAt = $this->clock->dueAt(now(), $hours * $warnPercent / 100, ! empty($sla['business_hours']), $calendar);
        WorkflowTimer::create(['company_id' => $task->company_id, 'instance_id' => $task->instance_id, 'task_id' => $task->id, 'kind' => 'sla_warning', 'fire_at' => $warnAt,
            'payload' => ['calendar_version' => $calendar?->version]]);
        WorkflowTimer::create(['company_id' => $task->company_id, 'instance_id' => $task->instance_id, 'task_id' => $task->id, 'kind' => 'sla_breach', 'fire_at' => $task->due_at,
            'payload' => ['escalation' => $sla['escalation'] ?? 'notify_manager', 'calendar_version' => $calendar?->version]]);
    }

    /**
     * Called after a task decision is recorded: decide whether its step is finished and, if so, continue.
     * The caller holds the instance lock.
     */
    public function afterDecision(WorkflowInstance $instance, WorkflowTask $task): void
    {
        $version = $instance->version()->first();
        $instance->setRelation('version', $version);
        $run = WorkflowNodeRun::query()->lockForUpdate()->findOrFail($task->node_run_id);
        if ($run->status !== 'waiting') {
            return; // the step already finished (another approver decided first)
        }
        $node = $version->node($run->node_key);
        $siblings = WorkflowTask::query()->where('node_run_id', $run->id)->get();
        $outcome = $this->stepOutcome($node, $siblings, $task);
        if ($outcome === null) {
            return;
        }
        foreach ($siblings as $s) {
            if ($s->status === 'open') {
                $this->closeTask($s, 'cancelled', 'Not needed: the step was decided.');
            }
        }
        $this->run($instance, $this->finish($instance, $version, $run, $outcome));
    }

    /** null while the step is still waiting for people. */
    private function stepOutcome(array $node, $tasks, WorkflowTask $last): ?string
    {
        $cfg = $node['config'] ?? [];
        if ($last->type === 'exception' && $node['type'] !== 'exception') {
            // A step that could not run was handled by hand. "Resolved" lets a task or an automated
            // step continue; an approval is never resolved this way — it is reassigned to a person who
            // may approve (TaskService refuses it), so nobody approves on another's behalf.
            return $last->decision === 'resolved' ? 'completed' : null;
        }
        if (in_array($last->decision, ['returned'], true)) {
            return 'returned';
        }
        if ($node['type'] === 'exception') {
            return $last->decision === 'cancelled' ? 'cancelled' : 'resolved';
        }
        if ($node['type'] !== 'approval') {
            return $last->decision === 'completed' ? 'completed' : null;
        }
        $decided = $tasks->whereNotNull('decision')->where('decision', '!=', 'info_requested');
        $approved = $decided->where('decision', 'approved')->count();
        $rejected = $decided->where('decision', 'rejected')->count();
        $open = $tasks->where('status', 'open')->count();
        $voters = $tasks->where('decision', '!=', 'abstained')->count();

        return match ($cfg['mode'] ?? 'any') {
            'all' => $rejected > 0 ? 'rejected' : ($open === 0 && $approved > 0 ? 'approved' : ($open === 0 ? 'rejected' : null)),
            'quorum' => $approved >= min((int) ($cfg['quorum'] ?? 1), max(1, $voters)) ? 'approved'
                : ($approved + $open < min((int) ($cfg['quorum'] ?? 1), max(1, $voters)) ? 'rejected' : null),
            default => $approved > 0 ? 'approved' : ($rejected > 0 ? 'rejected' : ($open === 0 ? 'rejected' : null)),
        };
    }

    public function closeTask(WorkflowTask $task, string $status, ?string $note = null): void
    {
        $task->status = $status;
        $task->completed_at = now();
        if ($note && ! $task->comment) {
            $task->comment = $note;
        }
        $task->save();
        WorkflowTimer::query()->where('task_id', $task->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    // ── Timers, actions, documents, sub-workflows ───────────────────────────

    /** A delay finished (called by the tick, holding the instance lock). */
    public function delayElapsed(WorkflowInstance $instance, WorkflowTimer $timer): void
    {
        $run = WorkflowNodeRun::query()->lockForUpdate()->find($timer->node_run_id);
        if (! $run || $run->status !== 'waiting') {
            return;
        }
        $version = $instance->version()->first();
        $instance->setRelation('version', $version);
        $this->run($instance, $this->finish($instance, $version, $run, 'completed'));
    }

    private function startAction(WorkflowInstance $instance, WorkflowVersion $version, array $node, WorkflowNodeRun $run): array
    {
        $cfg = $node['config'] ?? [];
        $key = (string) ($cfg['action'] ?? '');
        // §12, WF-019: checked again at run time — a permission withdrawn after publication still blocks.
        if (! ActionCatalog::permitted($key, (array) $this->settings->get('permitted_actions', $instance->company_id))) {
            $this->audit->denied('action.blocked', $instance, "Action \"{$key}\" is not permitted for this company.", ['instance' => $instance, 'context' => ['node' => $node['key']]]);
            $this->closeRun($run, 'failed', 'blocked');

            return $this->raiseException($instance, $node, "The action \"{$key}\" is not permitted, so \"{$node['name']}\" was blocked.");
        }
        $action = WorkflowActionRun::create([
            'company_id' => $instance->company_id, 'instance_id' => $instance->id, 'node_run_id' => $run->id, 'action_key' => $key,
            'idempotency_key' => "{$instance->uuid}:{$node['key']}:{$run->activation}",
            'status' => 'pending', 'max_attempts' => (int) ($cfg['max_attempts'] ?? 3),
            'input' => array_map(fn ($v) => is_string($v) ? Template::render($v, $instance->data) : $v, (array) ($cfg['input'] ?? [])),
        ]);
        $run->status = 'waiting';
        $run->detail = ['action_run' => $action->id];
        $run->save();

        return $this->executeAction($instance, $action);
    }

    /** Try an action once. On success the step continues; on failure it is retried by the tick. */
    public function executeAction(WorkflowInstance $instance, WorkflowActionRun $action): array
    {
        if (in_array($action->status, ['succeeded', 'cancelled'], true)) {
            return [];
        }
        $action->attempts++;
        try {
            $output = DB::transaction(fn () => $this->perform($instance, $action));
            $action->forceFill(['status' => 'succeeded', 'output' => $output, 'error' => null, 'next_attempt_at' => null])->save();
            $this->audit->record('action.succeeded', $instance, ['instance' => $instance, 'context' => ['action' => $action->action_key, 'attempt' => $action->attempts], 'after' => $output]);
        } catch (\Throwable $e) {
            // A refusal by a workflow or module control is a business outcome, already recorded and shown
            // under Exceptions; only the unexpected goes to the error log.
            if (! $e instanceof WorkflowException && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && ! $e instanceof \Illuminate\Validation\ValidationException) {
                report($e);
            }
            $final = $action->attempts >= $action->max_attempts;
            $action->forceFill([
                'status' => $final ? 'failed' : 'pending',
                'error' => mb_substr(class_basename($e) . ': ' . $e->getMessage(), 0, 1000),
                'next_attempt_at' => $final ? null : now()->addMinutes(2 ** $action->attempts),
            ])->save();
            $this->audit->failed('action.failed', $instance, $action->error, ['instance' => $instance, 'context' => ['action' => $action->action_key, 'attempt' => $action->attempts, 'final' => $final]]);
            if ($final) {
                $this->events->publish('workflow.action.failed', $this->eventPayload($instance) + ['action' => $action->action_key, 'action_run' => $action->id]);
                $instance->forceFill(['error' => "Action \"{$action->action_key}\" failed after {$action->attempts} attempt(s): {$action->error}"])->save();
            }

            return [];
        }

        if ($action->action_key === 'provider.outcome') {
            $this->finalise($instance);

            return [];
        }
        $run = WorkflowNodeRun::query()->find($action->node_run_id);
        if (! $run || $run->status !== 'waiting') {
            return [];
        }
        $version = $instance->relationLoaded('version') ? $instance->version : $instance->version()->first();

        return $this->finish($instance, $version, $run, 'completed');
    }

    /** Retry a failed or due action (tick, or an administrator's Retry). Holds the instance lock. */
    public function retryAction(WorkflowInstance $instance, WorkflowActionRun $action): void
    {
        $next = $this->executeAction($instance, $action);
        if ($next) {
            $instance->setRelation('version', $instance->version()->first());
            $this->run($instance, $next);
        }
    }

    private function perform(WorkflowInstance $instance, WorkflowActionRun $action): array
    {
        $in = $action->input ?? [];

        switch ($action->action_key) {
            case 'set_value':
                $field = (string) ($in['field'] ?? '');
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,59}$/', $field) || in_array($field, ['record', 'requester'], true)) {
                    throw new WorkflowException("\"{$field}\" is not a field this action may set.");
                }
                $data = $instance->data;
                $data[$field] = $in['value'] ?? null;
                $instance->data = $data;
                $instance->save();

                return ['field' => $field, 'value' => $in['value'] ?? null];

            case 'add_comment':
                $c = WorkflowComment::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'user_id' => null, 'body' => (string) ($in['message'] ?? '')]);

                return ['comment_id' => $c->id];

            case 'schedule_reminder':
                $to = $this->resolver->resolve((array) ($in['to'] ?? []), $instance);
                $at = now()->addDays(max(1, (int) ($in['days'] ?? 1)));
                WorkflowTimer::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'kind' => 'reminder', 'fire_at' => $at,
                    'payload' => ['to' => $to, 'message' => (string) ($in['message'] ?? '')]]);

                return ['fire_at' => $at->toIso8601String()];

            case 'mark_record':
            case 'provider.outcome':
                $provider = $this->providers->forSubjectType($instance->subject_type);
                $record = $provider && $instance->subject_id ? $provider->find($instance->subject_id) : null;
                if (! $record) {
                    if ($action->action_key === 'mark_record') {
                        throw new WorkflowException('This request has no business record to update.');
                    }

                    return ['outcome' => $in['outcome'] ?? null, 'record' => null];
                }
                $provider->onOutcome($record, (string) $in['outcome'], $in['actor_id'] ?? null, $in['comment'] ?? null);

                return ['outcome' => $in['outcome'], 'record' => $instance->subject_ref];
        }

        throw new WorkflowException("Unknown action \"{$action->action_key}\".");
    }

    private function generateDocument(WorkflowInstance $instance, WorkflowVersion $version, array $node, WorkflowNodeRun $run): array
    {
        $cfg = $node['config'] ?? [];
        $title = Template::render($cfg['title'] ?? $node['name'], $instance->data);
        $body = Template::render($cfg['body'] ?? '', $instance->data, $this->sensitiveFields($instance));
        try {
            $html = view('workflow.document', ['title' => $title, 'body' => $body, 'instance' => $instance])->render();
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
            $path = tempnam(sys_get_temp_dir(), 'wfdoc');
            file_put_contents($path, $pdf);
            $file = new \Illuminate\Http\UploadedFile($path, Str::slug($title) . '.pdf', 'application/pdf', null, true);
            $result = app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->store($file, ['title' => $title, 'description' => "Generated by {$instance->reference}"]);
            @unlink($path);
            if (empty($result['document'])) {
                throw new WorkflowException($result['error'] ?? 'Document Management refused the document.');
            }
            $instance->attachDocument($result['document'], 'workflow:document');
            $run->detail = ['document_id' => $result['document']->id, 'title' => $title];
        } catch (\Throwable $e) {
            report($e);
            $this->closeRun($run, 'failed', 'document_failed');

            return $this->raiseException($instance, $node, "The document for \"{$node['name']}\" could not be generated: " . $e->getMessage());
        }

        return $this->finish($instance, $version, $run, 'completed');
    }

    private function startChild(WorkflowInstance $instance, WorkflowVersion $version, array $node, WorkflowNodeRun $run): array
    {
        $cfg = $node['config'] ?? [];
        $child = WorkflowDefinition::query()->where('key', (string) ($cfg['definition_key'] ?? ''))->first();
        if (! $child || $child->status !== 'published') {
            $this->closeRun($run, 'failed', 'no_child');

            return $this->raiseException($instance, $node, "Sub-workflow \"{$node['name']}\" refers to a workflow that is not published.");
        }
        $data = ! empty($cfg['pass_data']) ? array_diff_key($instance->data, array_flip(['requester', 'record'])) : [];
        $childInstance = $this->start($child, $data, $instance->requester_id,
            ['type' => $instance->subject_type, 'id' => $instance->subject_id, 'ref' => $instance->subject_ref],
            "child:{$instance->uuid}:{$node['key']}:{$run->activation}", $instance->id, $child->name . ' (for ' . $instance->reference . ')');
        $run->detail = ['child' => $childInstance->uuid];
        if (empty($cfg['wait'])) {
            return $this->finish($instance, $version, $run, 'completed');
        }
        if (! $childInstance->isOpen()) {
            return $this->finish($instance, $version, $run, $childInstance->status === 'completed' ? 'completed' : 'rejected');
        }
        $run->status = 'waiting';
        $run->save();

        return [];
    }

    /** A child finished: continue its parent, if it waits (holds the parent's lock). */
    private function resumeParent(WorkflowInstance $child): void
    {
        if (! $child->parent_instance_id) {
            return;
        }
        $parent = WorkflowInstance::query()->lockForUpdate()->find($child->parent_instance_id);
        if (! $parent || $parent->status !== 'running') {
            return;
        }
        $run = WorkflowNodeRun::query()->where('instance_id', $parent->id)->where('status', 'waiting')
            ->where('node_type', 'subworkflow')->whereRaw("detail->>'child' = ?", [$child->uuid])->lockForUpdate()->first();
        if (! $run) {
            return;
        }
        $version = $parent->version()->first();
        $parent->setRelation('version', $version);
        $this->run($parent, $this->finish($parent, $version, $run, $child->status === 'completed' ? 'completed' : 'rejected'));
    }

    // ── Finishing ───────────────────────────────────────────────────────────

    /**
     * The instance reached an End (or was cancelled). Open work stops; if a module owns the record,
     * the outcome is handed to it, and only when that succeeds is the instance shown as finished (§17:
     * never "completed" because something was merely queued).
     */
    public function terminate(WorkflowInstance $instance, string $endOutcome, ?int $actorId, ?string $comment): void
    {
        $this->cancelOpenWork($instance, $endOutcome === 'cancelled' ? 'The request was cancelled.' : 'The request finished.');
        $recordOutcome = ['completed' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$endOutcome] ?? 'approved';
        $instance->outcome = $recordOutcome;
        $instance->save();

        if ($instance->subject_type && $this->providers->has($instance->subject_type) && $instance->subject_id) {
            $lastDecider = $actorId ?? WorkflowTask::query()->where('instance_id', $instance->id)->whereNotNull('decided_by')->orderByDesc('completed_at')->value('decided_by');
            $lastComment = $comment ?? WorkflowTask::query()->where('instance_id', $instance->id)->whereNotNull('decided_by')->orderByDesc('completed_at')->value('comment');
            $action = WorkflowActionRun::firstOrCreate(
                ['idempotency_key' => "{$instance->uuid}:outcome"],
                ['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'action_key' => 'provider.outcome', 'status' => 'pending', 'max_attempts' => 5,
                    'input' => ['outcome' => $recordOutcome, 'actor_id' => $lastDecider, 'comment' => $lastComment]],
            );
            $this->executeAction($instance, $action);

            return;
        }
        $this->finalise($instance);
    }

    private function finalise(WorkflowInstance $instance): void
    {
        $status = ['approved' => 'completed', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$instance->outcome] ?? 'completed';
        $instance->forceFill(['status' => $status, 'completed_at' => now(), 'error' => null])->save();
        $this->audit->record('instance.' . $status, $instance, ['instance' => $instance, 'after' => ['outcome' => $instance->outcome]]);
        $this->events->publish('workflow.instance.' . $status, $this->eventPayload($instance) + ['outcome' => $instance->outcome]);
        if ($instance->requester_id) {
            $this->notifier->send([$instance->requester_id], 'finished', "{$instance->reference} " . ['completed' => 'was approved', 'rejected' => 'was rejected', 'cancelled' => 'was cancelled'][$status],
                $instance->title, url('/workflow/instances/' . $instance->uuid), $instance, 'finished');
        }
        $this->resumeParent($instance);
    }

    /** Stop everything still open in the instance (tasks, timers, pending actions, waiting steps). */
    public function cancelOpenWork(WorkflowInstance $instance, string $why, ?int $exceptRunId = null): void
    {
        $runs = WorkflowNodeRun::query()->where('instance_id', $instance->id)->whereIn('status', ['active', 'waiting'])
            ->when($exceptRunId, fn ($q) => $q->where('id', '!=', $exceptRunId))->get();
        foreach ($runs as $r) {
            $this->closeRun($r, 'cancelled', 'cancelled');
        }
        $runIds = $runs->pluck('id');
        WorkflowTask::query()->where('instance_id', $instance->id)->where('status', 'open')
            ->when($exceptRunId, fn ($q) => $q->whereIn('node_run_id', $runIds))->get()
            ->each(fn ($t) => $this->closeTask($t, 'cancelled', $why));
        WorkflowTimer::query()->where('instance_id', $instance->id)->where('status', 'pending')->where('kind', '!=', 'reminder')
            ->when($exceptRunId, fn ($q) => $q->where(fn ($w) => $w->whereIn('node_run_id', $runIds)->orWhereIn('task_id', WorkflowTask::query()->whereIn('node_run_id', $runIds)->select('id'))))
            ->update(['status' => 'cancelled']);
        WorkflowActionRun::query()->where('instance_id', $instance->id)->where('status', 'pending')->where('action_key', '!=', 'provider.outcome')
            ->when($exceptRunId, fn ($q) => $q->whereIn('node_run_id', $runIds))->update(['status' => 'cancelled']);
        if (! $exceptRunId) {
            // Children started by this request stop with it (§9: cancellation reaches parallel and child work).
            WorkflowInstance::query()->where('parent_instance_id', $instance->id)->whereIn('status', WorkflowInstance::OPEN)->get()
                ->each(function ($child) use ($why) {
                    $child = WorkflowInstance::query()->lockForUpdate()->find($child->id);
                    $child->parent_instance_id = null; // do not resume a parent that is itself stopping
                    $this->terminate($child, 'cancelled', null, $why);
                });
        }
    }

    /** Cancel a running request (holds the lock). */
    public function cancel(WorkflowInstance $instance, ?int $actorId, string $reason): void
    {
        if (! $instance->isOpen()) {
            throw new WorkflowException('Only a running request can be cancelled.');
        }
        $instance->status = 'running';
        $this->audit->record('instance.cancel_requested', $instance, ['instance' => $instance, 'reason' => $reason]);
        WorkflowComment::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'user_id' => $actorId, 'body' => 'Cancelled: ' . $reason]);
        $this->terminate($instance, 'cancelled', $actorId, $reason);
    }

    /** Mark the instance failed: an engine fault, not a business outcome. */
    public function fail(WorkflowInstance $instance, string $message): void
    {
        $this->cancelOpenWork($instance, $message);
        $instance->forceFill(['status' => 'failed', 'error' => $message, 'completed_at' => now()])->save();
        $this->audit->failed('instance.failed', $instance, $message, ['instance' => $instance]);
        $this->events->publish('workflow.instance.failed', $this->eventPayload($instance));
    }

    /** A step could not run: raise a manual-intervention task (PRS §17) instead of stopping silently. */
    private function raiseException(WorkflowInstance $instance, array $node, string $message): array
    {
        $run = WorkflowNodeRun::query()->where('instance_id', $instance->id)->where('node_key', $node['key'])->orderByDesc('activation')->first();
        $instance->forceFill(['error' => $message])->save();
        $people = $this->fallbackAssignees($instance);
        $task = WorkflowTask::create([
            'company_id' => $instance->company_id, 'instance_id' => $instance->id, 'node_run_id' => $run->id, 'node_key' => $node['key'],
            'type' => 'exception', 'name' => 'Needs attention: ' . ($node['name'] ?? $node['key']), 'status' => 'open',
            'assignee_id' => count($people) === 1 ? $people[0] : null, 'candidates' => count($people) === 1 ? [] : $people,
            'priority' => 'high', 'comment' => $message,
        ]);
        if ($run->status !== 'waiting') {
            $run->status = 'waiting';
            $run->detail = array_merge($run->detail ?? [], ['exception' => $message]);
            $run->save();
        }
        $this->audit->failed('step.exception', $instance, $message, ['instance' => $instance, 'context' => ['node' => $node['key'], 'task' => $task->uuid]]);
        $this->notifier->send($people, 'exception', 'Needs attention: ' . ($node['name'] ?? ''), "{$instance->reference}: {$message}", url('/workflow/tasks/' . $task->uuid), $instance, 'exception:' . $task->id);

        return [];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function openRun(WorkflowInstance $instance, array $node, ?string $via): WorkflowNodeRun
    {
        $activation = (int) WorkflowNodeRun::query()->where('instance_id', $instance->id)->where('node_key', $node['key'])->max('activation') + 1;

        return WorkflowNodeRun::create([
            'company_id' => $instance->company_id, 'instance_id' => $instance->id, 'node_key' => $node['key'], 'node_type' => $node['type'],
            'activation' => $activation, 'status' => 'active', 'via_edge' => $via, 'started_at' => now(),
        ]);
    }

    private function closeRun(WorkflowNodeRun $run, string $status, ?string $outcome): void
    {
        $run->status = $status;
        $run->outcome = $outcome;
        $run->finished_at = now();
        $run->save();
    }

    /** @return list<string> */
    public function sensitiveFields(WorkflowInstance $instance): array
    {
        $fields = [];
        $version = $instance->relationLoaded('version') ? $instance->version : $instance->version()->first();
        foreach (($version->pinned_forms ?? []) as $formVersionId) {
            foreach (\App\Modules\Workflow\Models\WorkflowFormVersion::query()->find($formVersionId)?->fields ?? [] as $f) {
                if (! empty($f['sensitive'])) {
                    $fields[] = $f['key'];
                }
            }
        }

        return $fields;
    }

    public function eventPayload(WorkflowInstance $instance): array
    {
        return ['company_id' => $instance->company_id, 'instance' => $instance->uuid, 'reference' => $instance->reference,
            'definition' => $instance->definition?->key ?? WorkflowDefinition::query()->whereKey($instance->definition_id)->value('key'),
            'subject_type' => $instance->subject_type, 'subject_id' => $instance->subject_id];
    }

    private function nextReference(?int $companyId): string
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('workflow_ref:' . ($companyId ?? 0))]);
        }
        $prefix = 'WF-' . now()->format('Y') . '-';
        $last = WorkflowInstance::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('reference', 'like', $prefix . '%')->orderByDesc('reference')->value('reference');
        $n = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
