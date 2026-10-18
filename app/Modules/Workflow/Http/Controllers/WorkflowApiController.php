<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowAuditEvent;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowFormVersion;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Services\FormService;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\WorkflowAccess;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Services\Workflows;
use App\Modules\Workflow\Support\ProviderRegistry;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Versioned API for modules and approved external systems (PRS §21): the same services, permissions,
 * controls and audit as the screens. Errors are application/problem+json. Authentication is a Sanctum
 * token whose user's permissions apply; an integration uses a dedicated user with only what it needs.
 */
class WorkflowApiController extends Controller
{
    public function __construct(private readonly Presenter $present, private readonly WorkflowAccess $access, private readonly WorkflowAudit $audit)
    {
    }

    private function problem(int $status, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        return response()->json(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail] + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }

    private function guard(string $ability, string $action): ?JsonResponse
    {
        if (request()->user()?->can($ability)) {
            return null;
        }
        $this->audit->denied('api.' . $action, 'Permission', "Missing {$ability}", ['context' => ['path' => request()->path()]]);

        return $this->problem(403, 'Forbidden', "This token's user lacks {$ability}.");
    }

    private function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (WorkflowException $e) {
            return $this->problem(422, 'Refused by a workflow control', $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->problem(422, 'Invalid request', 'Some values are not valid.', ['errors' => $e->errors()]);
        }
    }

    public function start(Request $request): JsonResponse
    {
        if ($r = $this->guard(WorkflowPermissions::START, 'start')) {
            return $r;
        }
        $d = $request->validate(['definition' => ['required', 'string', 'max:80'], 'values' => ['array'], 'title' => ['nullable', 'string', 'max:200']]);
        $definition = WorkflowDefinition::query()->where('key', $d['definition'])->first();
        if (! $definition) {
            return $this->problem(404, 'Not found', 'No workflow with that key.');
        }
        $start = collect($definition->currentVersion?->graph['nodes'] ?? [])->firstWhere('type', 'start');
        $fv = ! empty($start['config']['form_id']) ? ($definition->currentVersion->pinned_forms[$start['config']['form_id']] ?? null) : null;
        [$clean, $errors] = app(FormService::class)->validate($fv ? WorkflowFormVersion::query()->find($fv)?->fields ?? [] : [], (array) ($d['values'] ?? []));
        if ($errors) {
            return $this->problem(422, 'Invalid request', 'Some values are not valid.', ['errors' => $errors]);
        }
        $key = $request->header('Idempotency-Key');

        return $this->attempt(function () use ($definition, $clean, $request, $key, $d) {
            $instance = DB::transaction(fn () => app(WorkflowRunner::class)->start($definition, $clean, $request->user()->id, [],
                $key ? 'api:' . $request->user()->id . ':' . mb_substr($key, 0, 80) : null, title: $d['title'] ?? null));

            return response()->json(['data' => $this->present->instance($instance->refresh()->load('definition'))], $instance->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function show(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $instance->load('definition');
        if (! $this->access->canView($request->user(), $instance)) {
            $this->audit->denied('api.instance.view', $instance, 'Not a participant or authorised viewer.', ['instance' => $instance]);

            return $this->problem(403, 'Forbidden');
        }

        return response()->json(['data' => $this->present->instance($instance) + [
            'open_tasks' => $instance->tasks()->where('status', 'open')->get()->map(fn ($t) => ['uuid' => $t->uuid, 'name' => $t->name, 'assignee' => $this->present->name($t->assignee_id), 'due_at' => $t->due_at?->toIso8601String()]),
        ]]);
    }

    public function tasks(Request $request): JsonResponse
    {
        if ($r = $this->guard(WorkflowPermissions::VIEW, 'tasks')) {
            return $r;
        }
        $mine = WorkflowTask::query()->with('instance.definition:id,name,category,is_confidential')->where('status', 'open')
            ->where(fn ($q) => $q->whereIn('assignee_id', $this->access->actingFor($request->user()))->orWhere(fn ($w) => $w->whereNull('assignee_id')->whereJsonContains('candidates', $request->user()->id)))
            ->orderByRaw('due_at IS NULL, due_at')->paginate(50);

        return response()->json(['data' => $mine->getCollection()->map(fn ($t) => $this->present->task($t)), 'meta' => ['total' => $mine->total(), 'page' => $mine->currentPage()]]);
    }

    public function claim(Request $request, WorkflowTask $task): JsonResponse
    {
        return $this->attempt(function () use ($request, $task) {
            app(TaskService::class)->claim($task, $request->user());

            return response()->json(['data' => $this->present->task($task->refresh())]);
        });
    }

    public function decide(Request $request, WorkflowTask $task): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'string', 'in:approved,rejected,returned,info_requested,completed,abstained,resolved,cancelled'],
            'comment' => ['nullable', 'string', 'max:4000'], 'values' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request, $task, $d) {
            $t = app(TaskService::class)->decide($task, $request->user(), $d['decision'], $d['comment'] ?? null, $d['values'] ?? []);

            return response()->json(['data' => $this->present->task($t->load('instance.definition'))]);
        });
    }

    public function comment(Request $request, WorkflowInstance $instance): JsonResponse
    {
        if (! $this->access->canView($request->user(), $instance->load('definition'))) {
            return $this->problem(403, 'Forbidden');
        }
        $d = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $c = app(TaskService::class)->comment($instance, $request->user(), $d['body']);

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    public function cancel(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        if ($instance->requester_id !== $request->user()->id && ! $request->user()->can(WorkflowPermissions::MANAGE)) {
            $this->audit->denied('api.instance.cancel', $instance, 'Not the requester or a process manager.', ['instance' => $instance]);

            return $this->problem(403, 'Forbidden');
        }

        return $this->attempt(function () use ($request, $instance, $d) {
            DB::transaction(fn () => app(WorkflowRunner::class)->cancel(WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->id), $request->user()->id, $d['reason']));

            return response()->json(['data' => $this->present->instance($instance->refresh()->load('definition'))]);
        });
    }

    public function audit(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $instance->load('definition');
        if (! $request->user()->can(WorkflowPermissions::AUDIT) && ! $this->access->isParticipant($request->user(), $instance)) {
            return $this->problem(403, 'Forbidden');
        }

        return response()->json(['data' => WorkflowAuditEvent::query()->where('instance_id', $instance->id)->orderBy('id')->get()
            ->map(fn ($e) => ['at' => $e->created_at?->toIso8601String(), 'actor' => $e->actor_name, 'action' => $e->action, 'outcome' => $e->outcome, 'reason' => $e->reason, 'hash' => $e->hash])]);
    }

    /** A registered module reports an event for one of its records (PRS §5, §13). */
    public function event(Request $request, ProviderRegistry $providers): JsonResponse
    {
        if ($r = $this->guard(WorkflowPermissions::START, 'event')) {
            return $r;
        }
        $d = $request->validate(['provider' => ['required', 'string', 'in:' . implode(',', array_keys(ProviderRegistry::PROVIDERS))], 'record_id' => ['required', 'string', 'max:64'],
            'event_id' => ['required', 'string', 'max:80']]);
        $provider = $providers->get($d['provider']);
        if (! $provider->find($d['record_id'])) {
            return $this->problem(404, 'Not found', 'No such record.');
        }

        return $this->attempt(function () use ($request, $provider, $d) {
            $started = app(Workflows::class)->emit($provider->submitEvent(), $provider->key(), $d['record_id'], $request->user()->id, [], $d['event_id']);

            return response()->json(['data' => array_map(fn ($i) => $this->present->instance($i->load('definition')), $started)], $started ? 202 : 200);
        });
    }

    public function retry(Request $request, int $run): JsonResponse
    {
        if ($r = $this->guard(WorkflowPermissions::MANAGE, 'action.retry')) {
            return $r;
        }

        return $this->attempt(function () use ($run) {
            $status = DB::transaction(function () use ($run) {
                $action = WorkflowActionRun::query()->lockForUpdate()->findOrFail($run);
                $instance = WorkflowInstance::query()->lockForUpdate()->findOrFail($action->instance_id);
                if (! in_array($action->status, ['failed', 'pending'], true)) {
                    throw new WorkflowException('Only a failed or waiting action can be retried.');
                }
                $action->forceFill(['status' => 'pending', 'max_attempts' => $action->attempts + 1])->save();
                app(WorkflowRunner::class)->retryAction($instance, $action);

                return $action->fresh()->status;
            });

            return response()->json(['data' => ['status' => $status]]);
        });
    }
}
