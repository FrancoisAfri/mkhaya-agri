<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNodeRun;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Services\WorkflowAccess;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Services\WorkflowEvents;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The failure queue (PRS §17): failed and stuck actions, exception tasks, undelivered events; retry or resolve. */
class WorkflowOperationsController extends WorkflowController
{
    public function __construct(private readonly Presenter $present, private readonly WorkflowAudit $audit)
    {
    }

    public function exceptions(Request $request, WorkflowAccess $access): Response
    {
        $this->allow(WorkflowPermissions::MANAGE);
        $visible = $access->scopeVisible(WorkflowInstance::query(), $request->user(), asManager: true)->select('id');
        $actions = WorkflowActionRun::query()->with('instance.definition:id,name')->whereIn('instance_id', $visible)
            ->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($w) => $w->where('status', 'pending')->where('attempts', '>', 0)))->orderByDesc('updated_at')->limit(200)->get();
        $tasks = WorkflowTask::query()->with('instance.definition:id,name,category,is_confidential')->whereIn('instance_id', $visible)->where('status', 'open')
            ->where(fn ($q) => $q->where('type', 'exception')->orWhere('sla_state', 'breached'))->orderBy('created_at')->limit(200)->get();
        $failedInstances = WorkflowInstance::query()->with('definition:id,name')->whereIn('id', $visible)->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($w) => $w->where('status', 'running')->whereNotNull('error')))
            ->orderByDesc('updated_at')->limit(100)->get();

        return Inertia::render('Modules/Workflow/Exceptions', [
            'actions' => $actions->map(fn ($a) => ['id' => $a->id, 'action' => $a->action_key, 'status' => $a->status, 'attempts' => $a->attempts, 'max' => $a->max_attempts,
                'error' => $a->error, 'next' => $a->next_attempt_at?->toIso8601String(), 'updated' => $a->updated_at?->toIso8601String(),
                'instance' => ['uuid' => $a->instance->uuid, 'reference' => $a->instance->reference, 'title' => $a->instance->title, 'workflow' => $a->instance->definition?->name]]),
            'tasks' => $tasks->map(fn ($t) => $this->present->task($t)),
            'instances' => $failedInstances->map(fn ($i) => $this->present->instance($i)),
            'events' => DB::table('workflow_events')->where('status', 'failed')->orderByDesc('id')->limit(100)->get(['id', 'name', 'attempts', 'last_error', 'created_at']),
        ]);
    }

    public function retryAction(Request $request, int $run): RedirectResponse
    {
        $this->allow(WorkflowPermissions::MANAGE, 'action.retry');
        $action = WorkflowActionRun::query()->findOrFail($run);

        return $this->attempt(function () use ($action, $request) {
            DB::transaction(function () use ($action, $request) {
                $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($action->instance_id);
                $action = WorkflowActionRun::query()->lockForUpdate()->findOrFail($action->id);
                if (! in_array($action->status, ['failed', 'pending'], true)) {
                    throw new \App\Modules\Workflow\Exceptions\WorkflowException('Only a failed or waiting action can be retried.');
                }
                // §17: safe replay — the same idempotency key, so a module that already applied it does nothing twice.
                $action->forceFill(['status' => 'pending', 'max_attempts' => $action->attempts + 1, 'resolved_by' => $request->user()->id])->save();
                $this->audit->record('action.retried', $instance, ['instance' => $instance, 'context' => ['action' => $action->action_key, 'run' => $action->id]]);
                app(WorkflowRunner::class)->retryAction($instance, $action);
            });
            $fresh = $action->fresh();

            return $fresh->status === 'succeeded' ? 'Retried: it worked.' : "Retried, still failing: {$fresh->error}";
        }, 'Retried.');
    }

    /** Give up on an action and let the request continue (or, for a hand-off, mark it handled by hand). */
    public function skipAction(Request $request, int $run): RedirectResponse
    {
        $this->allow(WorkflowPermissions::MANAGE, 'action.skip');
        $d = $request->validate(['resolution' => ['required', 'string', 'min:10', 'max:2000']]);
        $action = WorkflowActionRun::query()->findOrFail($run);

        return $this->attempt(function () use ($action, $request, $d) {
            DB::transaction(function () use ($action, $request, $d) {
                $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($action->instance_id);
                $action = WorkflowActionRun::query()->lockForUpdate()->findOrFail($action->id);
                if (! in_array($action->status, ['failed', 'pending'], true)) {
                    throw new \App\Modules\Workflow\Exceptions\WorkflowException('This action is already finished.');
                }
                $action->forceFill(['status' => 'cancelled', 'resolved_by' => $request->user()->id, 'resolution' => $d['resolution']])->save();
                $this->audit->record('action.skipped', $instance, ['instance' => $instance, 'reason' => $d['resolution'], 'context' => ['action' => $action->action_key]]);
                $runner = app(WorkflowRunner::class);
                if ($action->action_key === 'provider.outcome') {
                    // Handled in the module by hand: the request is finished with the outcome it reached.
                    $instance->forceFill(['status' => ['approved' => 'completed', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$instance->outcome] ?? 'completed', 'completed_at' => now(), 'error' => null])->save();

                    return;
                }
                $nodeRun = WorkflowNodeRun::query()->find($action->node_run_id);
                if ($nodeRun && $nodeRun->status === 'waiting') {
                    $nodeRun->forceFill(['status' => 'completed', 'outcome' => 'skipped', 'finished_at' => now()])->save();
                    $instance->forceFill(['error' => null])->save();
                    $version = $instance->version()->first();
                    $instance->setRelation('version', $version);
                    $runner->run($instance, array_map(fn ($e) => [$e['to'], $e['key'] ?? null], $version->outgoing($nodeRun->node_key)));
                }
            });

            return 'Resolved by hand; the request continues.';
        }, 'Resolved.');
    }

    public function retryEvent(int $event, WorkflowEvents $events): RedirectResponse
    {
        $this->allow(WorkflowPermissions::MANAGE, 'event.retry');

        $ok = $events->retry($event);
        if ($ok) {
            $this->audit->record('event.retried', 'WorkflowEvent', ['subject_id' => $event]);
        }

        return back()->with($ok ? 'success' : 'error', $ok ? 'Queued for delivery on the next run of the workflow tick.' : 'Only a failed event can be retried.');
    }
}
