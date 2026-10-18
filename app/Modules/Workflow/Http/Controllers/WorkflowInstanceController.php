<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Services\InstanceView;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\WorkflowAccess;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Requests in flight (PRS §14 monitoring, §17 recovery) and the request screen. */
class WorkflowInstanceController extends WorkflowController
{
    public function __construct(private readonly WorkflowAccess $access, private readonly Presenter $present, private readonly WorkflowAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        $f = $request->validate(['status' => ['nullable', 'in:running,completed,rejected,cancelled,failed,suspended'], 'workflow' => ['nullable', 'uuid'],
            'sla' => ['nullable', 'in:warning,breached'], 'search' => ['nullable', 'string', 'max:100'], 'priority' => ['nullable', 'in:low,normal,high'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = $this->access->scopeVisible(WorkflowInstance::query(), $request->user(), asManager: true)->with('definition:id,uuid,name,category,is_confidential')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['workflow'] ?? null, fn ($q, $u) => $q->whereHas('definition', fn ($d) => $d->where('uuid', $u)))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($f['sla'] ?? null, fn ($q, $s) => $q->whereHas('tasks', fn ($t) => $t->where('status', 'open')->where('sla_state', $s)))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('started_at', '>=', $d))->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('started_at', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('reference', 'ilike', "%{$s}%")->orWhere('title', 'ilike', "%{$s}%")->orWhere('subject_ref', 'ilike', "%{$s}%")));
        $rows = $q->orderByDesc('id')->paginate(30)->withQueryString();
        $open = \App\Modules\Workflow\Models\WorkflowTask::query()->whereIn('instance_id', $rows->pluck('id'))->where('status', 'open')->get()->groupBy('instance_id');

        return Inertia::render('Modules/Workflow/Instances', [
            'instances' => $rows->through(fn ($i) => $this->present->instance($i) + [
                'waiting_on' => ($open[$i->id] ?? collect())->map(fn ($t) => $t->name . ' — ' . ($t->assignee_id ? $this->present->name($t->assignee_id) : 'queue'))->implode('; '),
                'sla' => ($open[$i->id] ?? collect())->pluck('sla_state')->contains('breached') ? 'breached' : (($open[$i->id] ?? collect())->pluck('sla_state')->contains('warning') ? 'warning' : null),
            ]),
            'filters' => $f,
            'workflows' => WorkflowDefinition::query()->orderBy('name')->get(['uuid', 'name']),
        ]);
    }

    public function show(Request $request, WorkflowInstance $instance, InstanceView $view): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        $this->mayView($request, $instance);

        return Inertia::render('Modules/Workflow/InstanceShow', $view->build($instance, $request->user()) + ['people' => $this->people($instance->company_id)]);
    }

    public function cancel(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $user = $request->user();
        if ($instance->requester_id !== $user->id && ! $user->can(WorkflowPermissions::MANAGE)) {
            $this->audit->denied('instance.cancel', $instance, 'Not the requester or a process manager.', ['instance' => $instance]);
            abort(403, 'You may not cancel this request.');
        }

        return $this->attempt(fn () => DB::transaction(fn () => app(WorkflowRunner::class)->cancel(WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->id), $user->id, $d['reason'])),
            'Request cancelled.');
    }

    public function comment(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $this->mayView($request, $instance);
        $d = $request->validate(['body' => ['required', 'string', 'min:1', 'max:4000']]);
        app(TaskService::class)->comment($instance, $request->user(), $d['body']);

        return back()->with('success', 'Comment added.');
    }

    public function respond(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:4000']]);

        return $this->attempt(fn () => app(TaskService::class)->respond($instance, $request->user(), $d['answer']), 'Your answer was sent; the approver can continue.');
    }

    public function attach(Request $request, WorkflowInstance $instance, DocumentService $documents): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        if (! $instance->isOpen() || ! $this->access->isParticipant($request->user(), $instance)) {
            abort(403, 'Only people taking part in a running request can add documents.');
        }
        $d = $request->validate(['file' => ['required', 'file', 'max:20480'], 'title' => ['required', 'string', 'max:200']]);
        $result = $documents->store($d['file'], ['title' => $d['title'], 'description' => "Evidence for {$instance->reference}"]);
        if (empty($result['document'])) {
            return back()->with('error', $result['error'] ?? 'The file was refused.');
        }
        $instance->attachDocument($result['document'], 'workflow:attachment');
        $this->audit->record('attachment.added', $instance, ['instance' => $instance, 'context' => ['document' => $result['document']->id, 'title' => $d['title']]]);

        return back()->with('success', 'Filed in Document Management and linked to the request.');
    }

    private function mayView(Request $request, WorkflowInstance $instance): void
    {
        $instance->loadMissing('definition');
        if (! $this->access->canView($request->user(), $instance)) {
            $this->audit->denied('instance.view', $instance, 'Not a participant or authorised viewer.', ['instance' => $instance]);
            abort(403, 'You cannot open this request.');
        }
    }
}
