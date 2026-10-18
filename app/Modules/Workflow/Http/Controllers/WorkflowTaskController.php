<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Models\WorkflowFormVersion;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Services\InstanceView;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\WorkflowAccess;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PRS §14 "My Work" and the task screen (§8). */
class WorkflowTaskController extends WorkflowController
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly WorkflowAccess $access,
        private readonly Presenter $present,
    ) {
    }

    public function myWork(Request $request): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        $user = $request->user();
        $actingFor = array_values(array_diff($this->access->actingFor($user), [$user->id]));
        $with = ['instance.definition:id,uuid,name,category,is_confidential'];
        $open = fn () => WorkflowTask::query()->with($with)->where('status', 'open');
        $row = fn ($t) => $this->present->task($t);

        $mine = $open()->where('assignee_id', $user->id)->orderByRaw('due_at IS NULL, due_at')->orderBy('id')->limit(200)->get();
        $queue = $open()->whereNull('assignee_id')->whereJsonContains('candidates', $user->id)->orderBy('id')->limit(100)->get();
        $delegated = $actingFor ? $open()->whereIn('assignee_id', $actingFor)->orderBy('due_at')->limit(100)->get() : collect();
        $waitingOnMe = WorkflowInstance::query()->with('definition:id,name')->where('requester_id', $user->id)->where('status', 'running')
            ->whereHas('tasks', fn ($q) => $q->where('status', 'open')->where('decision', 'info_requested'))->limit(50)->get();
        $done = WorkflowTask::query()->with($with)->where('decided_by', $user->id)->where('status', 'completed')->orderByDesc('completed_at')->limit(20)->get();

        return Inertia::render('Modules/Workflow/MyWork', [
            'mine' => $mine->map($row), 'queue' => $queue->map($row), 'delegatedToMe' => $delegated->map($row),
            'waitingOnMe' => $waitingOnMe->map(fn ($i) => $this->present->instance($i)),
            'recent' => $done->map($row),
            'myDelegations' => WorkflowDelegation::query()->activeAt()->where('from_user_id', $user->id)->get()
                ->map(fn ($d) => ['uuid' => $d->uuid, 'to' => $this->present->name($d->to_user_id), 'ends_at' => $d->ends_at->toIso8601String()]),
            'counts' => [
                'mine' => $mine->count(), 'approvals' => $mine->where('type', 'approval')->count(),
                'overdue' => $mine->filter(fn ($t) => $t->due_at && $t->due_at->isPast())->count(),
                'due_soon' => $mine->filter(fn ($t) => $t->due_at && $t->due_at->isFuture() && $t->due_at->lt(now()->addDay()))->count(),
                'queue' => $queue->count(), 'delegated' => $delegated->count(),
            ],
            'can' => ['start' => $this->can(WorkflowPermissions::START)],
        ]);
    }

    public function show(Request $request, WorkflowTask $task, InstanceView $view): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        $user = $request->user();
        $instance = $task->instance()->with(['definition', 'version'])->firstOrFail();
        $task->setRelation('instance', $instance);
        [$canAct, $onBehalfOf] = $this->access->canAct($user, $task);
        if (! $canAct && ! $this->access->canClaim($user, $task) && ! $this->access->canView($user, $instance)) {
            app(\App\Modules\Workflow\Services\WorkflowAudit::class)->denied('task.view', $task, 'Not a participant.', ['instance' => $instance]);
            abort(403, 'You cannot open this task.');
        }
        $node = $instance->version->node($task->node_key) ?? ['type' => $task->type, 'config' => []];
        $cfg = $node['config'] ?? [];
        $fields = [];
        if (! empty($cfg['form_id']) && ($fv = $instance->version->pinned_forms[$cfg['form_id']] ?? null)) {
            $fields = WorkflowFormVersion::query()->find($fv)?->fields ?? [];
        }

        return Inertia::render('Modules/Workflow/TaskShow', [
            'task' => $this->present->task($task) + ['instructions' => $cfg['instructions'] ?? null, 'node_type' => $node['type'] ?? $task->type,
                'mode' => $cfg['mode'] ?? null, 'history' => $task->history()->orderBy('id')->get()->map(fn ($h) => ['action' => $h->action,
                    'from' => $this->present->name($h->from_user_id), 'to' => $this->present->name($h->to_user_id), 'by' => $this->present->name($h->by_user_id), 'reason' => $h->reason, 'at' => $h->created_at?->toIso8601String()])],
            'request' => $view->build($instance, $user),
            'fields' => $fields,
            'decisions' => $canAct ? $this->tasks->decisionsFor($task, $node) : [],
            'onBehalfOf' => $this->present->name($onBehalfOf),
            'can' => [
                'act' => $canAct, 'claim' => $this->access->canClaim($user, $task),
                'release' => $task->isOpen() && $task->assignee_id === $user->id && ! empty($task->candidates),
                'delegate' => $task->isOpen() && $task->assignee_id === $user->id && ($cfg['allow_delegate'] ?? true) !== false,
                'reassign' => $task->isOpen() && $this->can(WorkflowPermissions::MANAGE),
                'summarise' => (bool) app(WorkflowSettings::class)->get('ai_enabled') && (bool) app(WorkflowSettings::class)->get('ai_may_read_data') && ! $instance->definition->is_confidential,
            ],
            'people' => $this->people($instance->company_id),
        ]);
    }

    public function decide(Request $request, WorkflowTask $task): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['decision' => ['required', 'string', 'in:approved,rejected,returned,info_requested,completed,abstained,resolved,cancelled'],
            'comment' => ['nullable', 'string', 'max:4000'], 'values' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request, $task, $d) {
            $this->tasks->decide($task, $request->user(), $d['decision'], $d['comment'] ?? null, $d['values'] ?? []);

            return redirect()->route('workflow.my_work')->with('success', ['approved' => 'Approved.', 'rejected' => 'Rejected.', 'returned' => 'Returned for correction.',
                'info_requested' => 'Information requested from the requester.', 'completed' => 'Task completed.', 'abstained' => 'Abstention recorded.',
                'resolved' => 'Resolved; the request continues.', 'cancelled' => 'The request was cancelled.'][$d['decision']]);
        }, 'Done.');
    }

    public function claim(Request $request, WorkflowTask $task): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);

        return $this->attempt(fn () => $this->tasks->claim($task, $request->user()), 'The task is yours.');
    }

    public function release(Request $request, WorkflowTask $task): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);

        return $this->attempt(fn () => $this->tasks->release($task, $request->user()), 'Returned to the queue.');
    }

    public function reassign(Request $request, WorkflowTask $task): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['user_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'as_manager' => ['boolean']]);

        return $this->attempt(fn () => $this->tasks->reassign($task, $request->user(), (int) $d['user_id'], $d['reason'], (bool) ($d['as_manager'] ?? false)),
            ! empty($d['as_manager']) ? 'Task reassigned.' : 'Task delegated.');
    }
}
