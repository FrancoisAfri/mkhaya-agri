<?php

namespace App\Modules\Workflow\Services;

use App\Core\User\Models\User;
use App\Modules\Workflow\Exceptions\WorkflowDenied;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowComment;
use App\Modules\Workflow\Models\WorkflowFormVersion;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTaskAssignment;
use App\Modules\Workflow\Models\WorkflowTimer;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Support\Facades\DB;

/**
 * What people do with tasks (PRS §8): decide, claim, delegate, reassign, ask for information,
 * comment. Every decision records the actor, time, outcome, comment, the workflow version and the
 * record's reference (§8.3), and the engine — not the screen — enforces self-approval and
 * repeat-approver rules, including for delegates (§8.4).
 */
class TaskService
{
    public function __construct(
        private readonly WorkflowRunner $runner,
        private readonly WorkflowAccess $access,
        private readonly WorkflowAudit $audit,
        private readonly WorkflowEvents $events,
        private readonly FormService $forms,
        private readonly Notifier $notifier,
        private readonly AssigneeResolver $resolver,
    ) {
    }

    /** Decisions each kind of task offers. */
    public function decisionsFor(WorkflowTask $task, array $node): array
    {
        $cfg = $node['config'] ?? [];

        return match ($task->type) {
            'approval' => array_values(array_filter(['approved', 'rejected', ! empty($cfg['allow_return']) ? 'returned' : null, 'info_requested', ! empty($cfg['allow_abstain']) ? 'abstained' : null])),
            'exception' => ($node['type'] ?? null) === 'approval' ? ['cancelled'] : ['resolved', 'cancelled'],
            default => array_values(array_filter(['completed', ! empty($cfg['allow_return']) ? 'returned' : null, 'info_requested'])),
        };
    }

    public function decide(WorkflowTask $task, User $user, string $decision, ?string $comment, array $values = []): WorkflowTask
    {
        return $this->audited(fn () => DB::transaction(function () use ($task, $user, $decision, $comment, $values) {
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($task->instance_id);
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            $task->setRelation('instance', $instance);
            $version = $instance->version()->first();
            $node = $version->node($task->node_key) ?? ['type' => $task->type, 'config' => []];
            $cfg = $node['config'] ?? [];

            if ($instance->status !== 'running') {
                throw new WorkflowException('This request is no longer running.');
            }
            [$may, $onBehalfOf] = $this->access->canAct($user, $task);
            if (! $may) {
                throw new WorkflowDenied('You cannot act on this task.', 'task.decide', $task, 'Not the assignee or an active delegate.', ['instance' => $instance, 'context' => ['decision' => $decision]]);
            }
            if (! in_array($decision, $this->decisionsFor($task, $node), true)) {
                throw new WorkflowException('That decision is not available on this task.');
            }
            $comment = trim((string) $comment);
            if (in_array($decision, ['rejected', 'returned', 'info_requested', 'cancelled'], true) && mb_strlen($comment) < 3) {
                throw new WorkflowException(['rejected' => 'Give the reason for rejecting.', 'returned' => 'Say what needs correcting.', 'info_requested' => 'Say what information you need.', 'cancelled' => 'Give the reason for cancelling.'][$decision]);
            }

            // §3 and WF-006: self-approval and repeat approvers, for the actor and anyone they act for.
            if ($task->type === 'approval' && in_array($decision, ['approved', 'rejected'], true)) {
                $people = array_filter([$user->id, $onBehalfOf]);
                if ($instance->requester_id && in_array($instance->requester_id, $people, true) && ! $this->runner->selfApprovalAllowed($instance, $cfg)) {
                    throw new WorkflowDenied('You cannot approve or reject your own request.', 'task.self_approval', $task, 'The requester may not decide their own request.', ['instance' => $instance, 'context' => ['decision' => $decision, 'on_behalf_of' => $onBehalfOf]]);
                }
                if (($cfg['distinct_approvers'] ?? true) !== false && WorkflowTask::query()->where('instance_id', $instance->id)->where('id', '!=', $task->id)
                    ->where('type', 'approval')->where('decision', 'approved')->whereIn('decided_by', $people)->exists()) {
                    throw new WorkflowDenied('You already approved an earlier level of this request; another person must decide this one.', 'task.repeat_approver', $task, 'Already approved an earlier level of this request.', ['instance' => $instance]);
                }
            }

            // A task's own form.
            $clean = [];
            if ($decision !== 'info_requested' && ! empty($cfg['form_id']) && ($formVersionId = ($version->pinned_forms[$cfg['form_id']] ?? null))) {
                $fields = WorkflowFormVersion::query()->find($formVersionId)?->fields ?? [];
                [$clean, $errors] = $this->forms->validate($fields, $values, $instance->data, partial: in_array($decision, ['returned', 'rejected', 'cancelled'], true));
                if ($errors) {
                    throw \Illuminate\Validation\ValidationException::withMessages(array_combine(array_map(fn ($k) => "values.{$k}", array_keys($errors)), array_values($errors)));
                }
            }

            if ($decision === 'info_requested') {
                return $this->requestInformation($instance, $task, $user, $comment);
            }
            if ($task->type === 'exception' && $decision === 'cancelled') {
                $this->record($instance, $task, $user, $onBehalfOf, $decision, $comment, []);
                $this->runner->cancel($instance, $user->id, $comment);

                return $task->refresh();
            }

            $this->record($instance, $task, $user, $onBehalfOf, $decision, $comment, $clean);
            if ($clean) {
                $data = $instance->data;
                foreach ($clean as $k => $v) {
                    $data[$k] = $v;
                }
                $instance->data = $data;
                $instance->save();
            }
            $this->runner->afterDecision($instance, $task);

            return $task->refresh();
        }));
    }

    /** Run $fn; a refusal inside it is audited after its transaction rolled back, then re-thrown. */
    private function audited(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (WorkflowDenied $e) {
            $e->audit();
            throw $e;
        }
    }

    private function record(WorkflowInstance $instance, WorkflowTask $task, User $user, ?int $onBehalfOf, string $decision, ?string $comment, array $values): void
    {
        $task->forceFill([
            'decision' => $decision, 'decided_by' => $user->id, 'decided_on_behalf_of' => $onBehalfOf, 'comment' => $comment ?: $task->comment,
            'data' => $values, 'status' => 'completed', 'completed_at' => now(), 'sla_state' => $task->sla_state === 'breached' ? 'breached' : ($task->sla_state === 'none' ? 'none' : 'on_track'),
        ])->save();
        WorkflowTimer::query()->where('task_id', $task->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        if ($comment) {
            WorkflowComment::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'task_id' => $task->id, 'user_id' => $user->id,
                'body' => ucfirst(str_replace('_', ' ', $decision)) . ($onBehalfOf ? ' (on behalf of ' . $this->audit->nameOf(User::find($onBehalfOf)) . ')' : '') . ': ' . $comment]);
        }
        $versionNo = $instance->version()->value('version');
        $this->audit->record('task.decided', $task, ['instance' => $instance, 'version' => $versionNo, 'reason' => $comment,
            'after' => ['decision' => $decision, 'node' => $task->node_key, 'on_behalf_of' => $onBehalfOf, 'record' => $instance->subject_ref]]);
        $payload = $this->runner->eventPayload($instance) + ['task' => $task->uuid, 'node' => $task->node_key, 'decision' => $decision];
        $this->events->publish('workflow.task.completed', $payload);
        if ($task->type === 'approval' && in_array($decision, ['approved', 'rejected'], true)) {
            $this->events->publish('workflow.approval.' . $decision, $payload);
        }
    }

    /** §8.2: ask the requester for more information. The task stays open and its SLA clock pauses. */
    private function requestInformation(WorkflowInstance $instance, WorkflowTask $task, User $user, string $question): WorkflowTask
    {
        WorkflowComment::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'task_id' => $task->id, 'user_id' => $user->id, 'body' => 'Information requested: ' . $question]);
        $task->forceFill(['decision' => 'info_requested', 'sla_state' => $task->sla_state === 'none' ? 'none' : 'paused',
            'data' => array_merge($task->data ?? [], ['paused_at' => now()->toIso8601String()])])->save();
        $this->audit->record('task.info_requested', $task, ['instance' => $instance, 'reason' => $question]);
        $this->notifier->send([$instance->requester_id], 'info', "More information needed on {$instance->reference}", $question, url('/workflow/instances/' . $instance->uuid), $instance, 'info:' . $task->id);

        return $task;
    }

    /** The requester answers; the task's clock resumes, its deadline moved by the time it was paused. */
    public function respond(WorkflowInstance $instance, User $user, string $answer): void
    {
        DB::transaction(function () use ($instance, $user, $answer) {
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->requester_id !== $user->id) {
                throw new WorkflowException('Only the requester can answer.');
            }
            $waiting = WorkflowTask::query()->where('instance_id', $instance->id)->where('status', 'open')->where('decision', 'info_requested')->lockForUpdate()->get();
            if ($waiting->isEmpty()) {
                throw new WorkflowException('Nobody is waiting for information on this request.');
            }
            WorkflowComment::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'user_id' => $user->id, 'body' => 'Response: ' . $answer]);
            foreach ($waiting as $task) {
                $pausedAt = isset($task->data['paused_at']) ? \Carbon\Carbon::parse($task->data['paused_at']) : now();
                $shift = max(0, (int) $pausedAt->diffInSeconds(now()));
                if ($task->due_at) {
                    $task->due_at = $task->due_at->copy()->addSeconds($shift);
                    WorkflowTimer::query()->where('task_id', $task->id)->where('status', 'pending')->get()
                        ->each(fn ($t) => $t->forceFill(['fire_at' => $t->fire_at->copy()->addSeconds($shift)])->save());
                }
                $data = $task->data ?? [];
                unset($data['paused_at']);
                $data['paused_seconds'] = (int) ($data['paused_seconds'] ?? 0) + $shift;
                $task->forceFill(['decision' => null, 'data' => $data, 'sla_state' => $task->sla_state === 'paused' ? 'on_track' : $task->sla_state])->save();
                $this->notifier->send([$task->assignee_id], 'info_answered', "{$instance->reference}: information provided", $answer, url('/workflow/tasks/' . $task->uuid), $instance, 'answer:' . $task->id);
            }
            $this->audit->record('instance.info_provided', $instance, ['instance' => $instance, 'reason' => $answer]);
        });
    }

    public function claim(WorkflowTask $task, User $user): void
    {
        DB::transaction(function () use ($task, $user) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            if (! $this->access->canClaim($user, $task)) {
                throw new WorkflowException($task->assignee_id ? 'Someone has already claimed this task.' : 'You are not in this task\'s queue.');
            }
            $task->forceFill(['assignee_id' => $user->id, 'original_assignee_id' => $user->id])->save();
            WorkflowTaskAssignment::create(['company_id' => $task->company_id, 'task_id' => $task->id, 'action' => 'claimed', 'to_user_id' => $user->id, 'by_user_id' => $user->id]);
            $this->audit->record('task.claimed', $task, ['instance' => $task->instance]);
        });
    }

    public function release(WorkflowTask $task, User $user): void
    {
        DB::transaction(function () use ($task, $user) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->assignee_id !== $user->id || empty($task->candidates)) {
                throw new WorkflowException('Only a claimed queue task can be released.');
            }
            $task->forceFill(['assignee_id' => null])->save();
            WorkflowTaskAssignment::create(['company_id' => $task->company_id, 'task_id' => $task->id, 'action' => 'released', 'from_user_id' => $user->id, 'by_user_id' => $user->id]);
            $this->audit->record('task.released', $task, ['instance' => $task->instance]);
        });
    }

    /**
     * Hand a task to someone else: the assignee delegating it (if the step allows), or a process
     * manager reassigning it. The new person must be able to act lawfully on it.
     */
    public function reassign(WorkflowTask $task, User $by, int $toUserId, string $reason, bool $asManager): void
    {
        $this->audited(fn () => DB::transaction(function () use ($task, $by, $toUserId, $reason, $asManager) {
            $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($task->instance_id);
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            $node = $instance->version()->first()->node($task->node_key) ?? ['type' => $task->type, 'config' => []];
            if (! $task->isOpen()) {
                throw new WorkflowException('Only an open task can be handed on.');
            }
            if ($asManager) {
                if (! $by->can(WorkflowPermissions::MANAGE)) {
                    throw new WorkflowDenied('You may not reassign tasks.', 'task.reassign', $task, 'Missing ' . WorkflowPermissions::MANAGE, ['instance' => $instance]);
                }
            } else {
                if ($task->assignee_id !== $by->id) {
                    throw new WorkflowException('Only the assignee can delegate this task.');
                }
                if (($node['config']['allow_delegate'] ?? true) === false) {
                    throw new WorkflowException('This step does not allow delegation.');
                }
            }
            if (mb_strlen(trim($reason)) < 3) {
                throw new WorkflowException('Give the reason for handing the task on.');
            }
            if (! $this->resolver->active([$toUserId], $instance->company_id)) {
                throw new WorkflowException('Choose an active person in this company.');
            }
            if ($toUserId === $task->assignee_id) {
                throw new WorkflowException('The task is already with that person.');
            }
            $approvalStep = $task->type === 'approval' || ($node['type'] ?? null) === 'approval';
            if ($approvalStep && $toUserId === $instance->requester_id && ! $this->runner->selfApprovalAllowed($instance, $node['config'] ?? [])) {
                throw new WorkflowException('An approval cannot be handed to the person who made the request.');
            }
            $from = $task->assignee_id;
            $updates = ['assignee_id' => $toUserId, 'candidates' => []];
            if ($task->type === 'exception' && in_array($node['type'] ?? null, ['approval', 'user_task', 'review'], true) && ! $task->decision) {
                // An unassignable step given to a person becomes that step's task again.
                $updates += ['type' => $node['type'], 'name' => $node['name'] ?? $task->name, 'original_assignee_id' => $toUserId];
            }
            $task->forceFill($updates)->save();
            WorkflowTaskAssignment::create(['company_id' => $task->company_id, 'task_id' => $task->id, 'action' => $asManager ? 'reassigned' : 'delegated',
                'from_user_id' => $from, 'to_user_id' => $toUserId, 'by_user_id' => $by->id, 'reason' => $reason]);
            $this->audit->record($asManager ? 'task.reassigned' : 'task.delegated', $task, ['instance' => $instance, 'reason' => $reason, 'before' => ['assignee' => $from], 'after' => ['assignee' => $toUserId]]);
            $this->notifier->send([$toUserId], 'assigned', 'Task handed to you: ' . $task->name, "{$instance->reference} — {$instance->title}. {$reason}", url('/workflow/tasks/' . $task->uuid), $instance, 'task:' . $task->id . ':' . $toUserId);
        }));
    }

    public function comment(WorkflowInstance $instance, User $user, string $body, ?WorkflowTask $task = null): WorkflowComment
    {
        $c = WorkflowComment::create(['company_id' => $instance->company_id, 'instance_id' => $instance->id, 'task_id' => $task?->id, 'user_id' => $user->id, 'body' => $body]);
        $this->audit->record('comment.added', $instance, ['instance' => $instance, 'context' => ['task' => $task?->uuid]]);
        $others = WorkflowTask::query()->where('instance_id', $instance->id)->where('status', 'open')->pluck('assignee_id')->push($instance->requester_id)
            ->filter(fn ($id) => $id && $id !== $user->id)->unique()->values()->all();
        $this->notifier->send($others, 'comment', "New comment on {$instance->reference}", mb_substr($body, 0, 200), url('/workflow/instances/' . $instance->uuid), $instance, 'comment:' . $c->id);

        return $c;
    }
}
