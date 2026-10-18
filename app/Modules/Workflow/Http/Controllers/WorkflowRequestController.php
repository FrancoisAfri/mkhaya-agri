<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowDraft;
use App\Modules\Workflow\Models\WorkflowFormVersion;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Services\FormService;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Starting requests (PRS §5 manual trigger, §6 forms) and the requester's own list. */
class WorkflowRequestController extends WorkflowController
{
    public function __construct(private readonly Presenter $present, private readonly FormService $forms)
    {
    }

    public function catalog(Request $request): Response
    {
        $this->allow(WorkflowPermissions::START);
        $user = $request->user();
        $drafts = WorkflowDraft::query()->where('user_id', $user->id)->pluck('updated_at', 'definition_id');

        return Inertia::render('Modules/Workflow/StartCatalog', [
            'workflows' => WorkflowDefinition::query()->where('status', 'published')->where('trigger_type', 'manual')->orderBy('category')->orderBy('name')->get()
                ->filter(fn ($d) => $this->mayStart($user, $d))
                ->map(fn ($d) => ['uuid' => $d->uuid, 'name' => $d->name, 'description' => $d->description, 'category' => $d->category ?: 'General',
                    'confidential' => $d->is_confidential, 'draft_saved' => isset($drafts[$d->id]) ? \Carbon\Carbon::parse($drafts[$d->id])->toIso8601String() : null])->values(),
        ]);
    }

    public function create(Request $request, WorkflowDefinition $definition): Response
    {
        $this->allow(WorkflowPermissions::START);
        abort_unless($definition->status === 'published' && $this->mayStart($request->user(), $definition), 403, 'You cannot start this workflow.');

        return Inertia::render('Modules/Workflow/StartForm', [
            'workflow' => ['uuid' => $definition->uuid, 'name' => $definition->name, 'description' => $definition->description, 'confidential' => $definition->is_confidential],
            'fields' => $this->startFields($definition),
            'draft' => WorkflowDraft::query()->where('user_id', $request->user()->id)->where('definition_id', $definition->id)->value('data'),
            'people' => $this->people($definition->company_id),
            'departments' => $this->departments($definition->company_id),
            'suppliers' => DB::getSchemaBuilder()->hasTable('suppliers') ? DB::table('suppliers')->where('is_active', true)->orderBy('company_name')->limit(500)->get(['id', 'company_name as name']) : [],
        ]);
    }

    public function store(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::START);
        abort_unless($definition->status === 'published' && $this->mayStart($request->user(), $definition), 403, 'You cannot start this workflow.');
        $values = (array) $request->input('values', []);
        abort_if(strlen(json_encode($values)) > 256000, 422, 'The request is too large.');
        [$clean, $errors] = $this->forms->validate($this->startFields($definition), $values, ['requester' => ['id' => $request->user()->id]]);
        if ($errors) {
            return back()->withErrors(array_combine(array_map(fn ($k) => "values.{$k}", array_keys($errors)), array_values($errors)))->withInput();
        }

        return $this->attempt(function () use ($request, $definition, $clean) {
            $instance = DB::transaction(function () use ($request, $definition, $clean) {
                $instance = app(WorkflowRunner::class)->start($definition, $clean, $request->user()->id,
                    dedupeKey: $request->header('Idempotency-Key') ? 'idem:' . $request->user()->id . ':' . mb_substr($request->header('Idempotency-Key'), 0, 80) : null);
                // Files uploaded on the form are linked to the request.
                foreach ($this->attachmentIds($this->startFields($definition), $clean) as $docId) {
                    // Only files this person uploaded: an id typed into the form cannot pull in someone else's document.
                    $doc = \App\Modules\Operations\DocumentManagement\Models\Document::query()->whereKey($docId)->where('created_by', $request->user()->id)->first();
                    if ($doc) {
                        $instance->attachDocument($doc, 'workflow:attachment');
                    }
                }
                WorkflowDraft::query()->where('user_id', $request->user()->id)->where('definition_id', $definition->id)->delete();

                return $instance;
            });

            return redirect()->route('workflow.instances.show', $instance->uuid)->with('success', "Submitted as {$instance->reference}.");
        }, 'Submitted.');
    }

    public function saveDraft(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::START);
        $values = (array) $request->input('values', []);
        abort_if(strlen(json_encode($values)) > 256000, 422, 'The draft is too large.');
        WorkflowDraft::query()->updateOrCreate(['user_id' => $request->user()->id, 'definition_id' => $definition->id], ['data' => $values]);

        return back()->with('success', 'Draft saved. You can come back to it from Start a request.');
    }

    /** A file for an attachment field: filed in Document Management (type guard, malware scan, quotas). */
    public function upload(Request $request, DocumentService $documents): JsonResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['file' => ['required', 'file', 'max:' . (int) $request->input('max_kb', 20480)], 'title' => ['nullable', 'string', 'max:200']]);
        $result = $documents->store($d['file'], ['title' => $d['title'] ?? $d['file']->getClientOriginalName(), 'description' => 'Workflow attachment']);
        if (empty($result['document'])) {
            return response()->json(['message' => $result['error'] ?? 'The file was refused.'], 422);
        }
        app(WorkflowAudit::class)->record('attachment.uploaded', $result['document'], ['context' => ['title' => $result['document']->title]]);

        return response()->json(['id' => $result['document']->id, 'title' => $result['document']->title]);
    }

    public function mine(Request $request): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        $status = $request->query('status');
        $rows = WorkflowInstance::query()->with(['definition:id,uuid,name,category,is_confidential', 'tasks' => fn ($t) => $t->where('status', 'open')])->where('requester_id', $request->user()->id)
            ->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('Modules/Workflow/MyRequests', [
            'requests' => $rows->through(fn ($i) => $this->present->instance($i) + [
                'waiting_on' => $i->tasks->map(fn ($t) => $t->assignee_id ? $this->present->name($t->assignee_id) : 'a queue')->unique()->implode(', '),
            ]),
            'filters' => ['status' => $status],
            'drafts' => WorkflowDraft::query()->where('user_id', $request->user()->id)->join('workflow_definitions as d', 'd.id', '=', 'workflow_drafts.definition_id')
                ->get(['d.name', 'd.uuid', 'workflow_drafts.updated_at'])->map(fn ($d) => ['workflow' => $d->name, 'uuid' => $d->uuid, 'saved' => $d->updated_at?->toIso8601String()]),
        ]);
    }

    public function searchPeople(Request $request): JsonResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        return response()->json(collect($this->people())->filter(fn ($p) => $q === '' || str_contains(mb_strtolower($p['name']), $q))->take(25)->values());
    }

    private function mayStart($user, WorkflowDefinition $d): bool
    {
        $roles = $d->start_roles ?? [];
        if ($roles && ! $user->hasAnyRole($roles)) {
            return false;
        }
        if ($d->is_confidential && $d->restricted_user_ids && ! in_array($user->id, array_map('intval', $d->restricted_user_ids), true) && ! ($d->trigger_config['anyone_may_start'] ?? false)) {
            return false;
        }

        return true;
    }

    private function startFields(WorkflowDefinition $d): array
    {
        $version = $d->currentVersion;
        $start = collect($version?->graph['nodes'] ?? [])->firstWhere('type', 'start');
        $fv = ! empty($start['config']['form_id']) ? ($version->pinned_forms[$start['config']['form_id']] ?? null) : null;

        return $fv ? (WorkflowFormVersion::query()->find($fv)?->fields ?? []) : [];
    }

    /** @return list<int> */
    private function attachmentIds(array $fields, array $values): array
    {
        $ids = [];
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'attachment') {
                $ids = array_merge($ids, (array) ($values[$f['key']] ?? []));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
