<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowCalendar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowForm;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\FormService;
use App\Modules\Workflow\Services\GraphValidator;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Support\ActionCatalog;
use App\Modules\Workflow\Support\Conditions;
use App\Modules\Workflow\Support\NodeTypes;
use App\Modules\Workflow\Support\ProviderRegistry;
use App\Modules\Workflow\Support\TemplateLibrary;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Workflow definitions: list, properties, the designer and the publication lifecycle (PRS §4, §16). */
class WorkflowDefinitionController extends WorkflowController
{
    public function __construct(private readonly DefinitionService $definitions, private readonly Presenter $present)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::DESIGN) || $this->can(WorkflowPermissions::PUBLISH) || $this->can(WorkflowPermissions::MANAGE), 403);
        $running = WorkflowInstance::query()->whereIn('status', WorkflowInstance::OPEN)->selectRaw('definition_id, count(*) n')->groupBy('definition_id')->pluck('n', 'definition_id');

        return Inertia::render('Modules/Workflow/Definitions', [
            'definitions' => WorkflowDefinition::query()->with(['currentVersion:id,version', 'versions:id,definition_id,version,status'])->orderBy('name')->get()->map(fn ($d) => [
                'uuid' => $d->uuid, 'key' => $d->key, 'name' => $d->name, 'category' => $d->category, 'module' => $d->module, 'status' => $d->status,
                'trigger' => $d->trigger_type, 'version' => $d->currentVersion?->version, 'owner' => $this->present->name($d->process_owner_id),
                'pending_review' => $d->versions->contains('status', 'in_review'), 'draft' => $d->versions->contains(fn ($v) => in_array($v->status, ['draft', 'rejected'], true)),
                'running' => (int) ($running[$d->id] ?? 0), 'confidential' => $d->is_confidential,
            ]),
            'modules' => $this->modules(),
            'categories' => app(WorkflowSettings::class)->categories(),
            'can' => ['design' => $this->can(WorkflowPermissions::DESIGN), 'ai' => (bool) app(WorkflowSettings::class)->get('ai_enabled')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'definition.create');
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', \Illuminate\Validation\Rule::in(app(WorkflowSettings::class)->categories())],
            'module' => ['required', 'string', 'in:' . implode(',', array_merge(['generic'], array_keys(ProviderRegistry::PROVIDERS)))]]);
        $attrs = $d + ['process_owner_id' => $request->user()->id];
        if ($d['module'] !== 'generic') {
            $attrs += ['trigger_type' => 'record_event', 'trigger_config' => ['event' => app(ProviderRegistry::class)->get($d['module'])->submitEvent()]];
        }
        $definition = $this->definitions->create($attrs);

        return redirect()->route('workflow.definitions.designer', $definition->uuid)->with('success', 'Workflow created. Design it, then publish.');
    }

    public function show(WorkflowDefinition $definition): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::DESIGN) || $this->can(WorkflowPermissions::PUBLISH) || $this->can(WorkflowPermissions::MANAGE), 403);
        $versions = $definition->versions()->orderByDesc('version')->get();
        $running = WorkflowInstance::query()->where('definition_id', $definition->id)->whereIn('status', WorkflowInstance::OPEN)->with('version:id,version')->orderByDesc('id')->limit(200)->get();

        return Inertia::render('Modules/Workflow/DefinitionShow', [
            'definition' => $definition->only(['uuid', 'key', 'name', 'description', 'category', 'tags', 'module', 'status', 'trigger_type', 'trigger_config', 'default_priority',
                'is_confidential', 'restricted_user_ids', 'start_roles', 'retention_days', 'calendar_id', 'process_owner_id', 'departments']) + ['owner' => $this->present->name($definition->process_owner_id)],
            'versions' => $versions->map(fn ($v) => ['uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status, 'change_summary' => $v->change_summary, 'origin' => $v->origin,
                'nodes' => count($v->graph['nodes'] ?? []), 'problems' => is_array($v->validation) ? count($v->validation) : null,
                'created_by' => $this->present->name($v->created_by), 'submitted_by' => $this->present->name($v->submitted_by), 'submitted_at' => $v->submitted_at?->toIso8601String(),
                'reviewed_by' => $this->present->name($v->reviewed_by), 'review_comment' => $v->review_comment, 'published_at' => $v->published_at?->toIso8601String(),
                'running' => $running->where('version_id', $v->id)->count()]),
            'running' => $running->map(fn ($i) => ['uuid' => $i->uuid, 'reference' => $i->reference, 'title' => $i->title, 'version' => $i->version?->version, 'status' => $i->status]),
            'people' => $this->people($definition->company_id),
            'roles' => DB::table('roles')->orderBy('name')->pluck('name'),
            'calendars' => WorkflowCalendar::query()->orderBy('name')->get(['id', 'name']),
            'categories' => app(WorkflowSettings::class)->categories($definition->company_id),
            'departments' => $this->departments($definition->company_id),
            'can' => ['design' => $this->can(WorkflowPermissions::DESIGN), 'publish' => $this->can(WorkflowPermissions::PUBLISH), 'manage' => $this->can(WorkflowPermissions::MANAGE),
                'ai' => (bool) app(WorkflowSettings::class)->get('ai_enabled'), 'me' => request()->user()->id],
            'requiresReview' => (bool) app(WorkflowSettings::class)->get('publication_requires_review'),
        ]);
    }

    public function update(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'definition.update');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', \Illuminate\Validation\Rule::in(app(WorkflowSettings::class)->categories())],
            'tags' => ['array'], 'tags.*' => ['string', 'max:40'], 'process_owner_id' => ['nullable', 'integer'], 'departments' => ['array'],
            'trigger_type' => ['required', 'in:manual,record_event,scheduled'], 'trigger_config' => ['array'],
            'trigger_config.frequency' => ['nullable', 'in:daily,weekly,monthly'], 'trigger_config.time' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'start_roles' => ['array'], 'start_roles.*' => ['string', 'exists:roles,name'], 'default_priority' => ['required', 'in:low,normal,high'],
            'calendar_id' => ['nullable', 'integer'], 'is_confidential' => ['boolean'], 'restricted_user_ids' => ['array'], 'restricted_user_ids.*' => ['integer'],
            'retention_days' => ['nullable', 'integer', 'min:30', 'max:36500'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        foreach (Conditions::problems($d['trigger_config']['filter'] ?? null) as $p) {
            return back()->withErrors(['trigger_config' => $p]);
        }
        if ($d['process_owner_id'] ?? null) {
            abort_unless(DB::table('users')->where('id', $d['process_owner_id'])->where('company_id', $definition->company_id)->exists(), 422, 'The process owner must be in this company.');
        }
        $reason = $d['reason'];
        unset($d['reason']);
        $this->definitions->updateProperties($definition, $d, $reason);

        return back()->with('success', 'Properties saved.');
    }

    public function designer(Request $request, WorkflowDefinition $definition): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::DESIGN) || $this->can(WorkflowPermissions::PUBLISH), 403);
        $version = $definition->workingVersion() ?? $definition->currentVersion ?? $definition->versions()->orderByDesc('version')->firstOrFail();
        $permitted = (array) app(WorkflowSettings::class)->get('permitted_actions');
        $provider = app(ProviderRegistry::class)->get($definition->module);

        return Inertia::render('Modules/Workflow/Designer', [
            'definition' => $definition->only(['uuid', 'name', 'module', 'status', 'category']) + ['process_owner' => $this->present->name($definition->process_owner_id)],
            'version' => ['uuid' => $version->uuid, 'version' => $version->version, 'status' => $version->status, 'graph' => DefinitionService::normalise($version->graph), 'validation' => $version->validation ?? [],
                'change_summary' => $version->change_summary, 'editable' => in_array($version->status, ['draft', 'rejected'], true) || ($version->status === 'published' && ! $definition->workingVersion())],
            'catalog' => [
                'nodes' => NodeTypes::TYPES, 'assignees' => NodeTypes::ASSIGNEE_TYPES, 'escalations' => NodeTypes::ESCALATIONS,
                'operators' => Conditions::OPERATORS, 'patterns' => array_keys(Conditions::PATTERNS),
                'actions' => collect(ActionCatalog::all())->map(fn ($a, $k) => $a + ['key' => $k, 'permitted' => ActionCatalog::permitted($k, $permitted)])->values(),
                'recordFields' => $provider?->fields() ?? [],
            ],
            'forms' => WorkflowForm::query()->with('currentVersion')->orderBy('name')->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name,
                'fields' => collect($f->currentVersion?->fields ?? [])->map(fn ($x) => ['key' => $x['key'], 'label' => $x['label'] ?? $x['key'], 'type' => $x['type']])->values()]),
            'roles' => DB::table('roles')->orderBy('name')->pluck('name'),
            'people' => $this->people($definition->company_id),
            'departments' => $this->departments($definition->company_id),
            'subworkflows' => WorkflowDefinition::query()->where('status', 'published')->where('id', '!=', $definition->id)->orderBy('name')->get(['key', 'name']),
            'can' => ['design' => $this->can(WorkflowPermissions::DESIGN), 'ai' => (bool) app(WorkflowSettings::class)->get('ai_enabled')],
            'requiresReview' => (bool) app(WorkflowSettings::class)->get('publication_requires_review'),
        ]);
    }

    public function saveDraft(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'version.save');
        $d = $request->validate(['graph' => ['required', 'array'], 'graph.nodes' => ['required', 'array'], 'graph.edges' => ['present', 'array'], 'change_summary' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(function () use ($definition, $d) {
            $v = $this->definitions->saveDraft($definition, $d['graph'], $d['change_summary'] ?? null);
            $n = count($v->validation ?? []);

            return $n ? "Draft saved. {$n} thing(s) to fix before it can be published." : 'Draft saved. It is ready to publish.';
        }, 'Saved.');
    }

    public function validateDraft(Request $request, WorkflowDefinition $definition, GraphValidator $validator): JsonResponse
    {
        $this->allow(WorkflowPermissions::DESIGN);
        $d = $request->validate(['graph' => ['required', 'array']]);

        return response()->json(['problems' => $validator->validate($d['graph'], $definition)]);
    }

    public function submit(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'version.submit');
        $d = $request->validate(['change_summary' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(function () use ($request, $definition, $d) {
            $v = $this->definitions->submit($definition, $request->user(), $d['change_summary']);

            return $v->status === 'published' ? "Version {$v->version} published. New requests use it; running ones stay on their version." : "Version {$v->version} sent for review.";
        }, 'Submitted.');
    }

    public function withdraw(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN);

        return $this->attempt(fn () => $this->definitions->withdraw($definition, $request->user()), 'Withdrawn; you can edit it again.');
    }

    public function review(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::PUBLISH, 'version.review');
        $d = $request->validate(['approve' => ['required', 'boolean'], 'comment' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(fn () => $this->definitions->review($definition, $request->user(), (bool) $d['approve'], $d['comment']),
            $d['approve'] ? 'Published.' : 'Sent back to the designer.');
    }

    public function status(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        // Permission before validation: a refused user learns nothing from the form's rules.
        if (! $this->can(WorkflowPermissions::MANAGE) && ! $this->can(WorkflowPermissions::PUBLISH)) {
            $this->allow(WorkflowPermissions::MANAGE, 'definition.status');
        }
        $d = $request->validate(['action' => ['required', 'in:suspend,resume,retire'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(fn () => $this->definitions->setStatus($definition, $d['action'], $d['reason']), ['suspend' => 'Suspended: no new requests can start.', 'resume' => 'Resumed.', 'retire' => 'Retired.'][$d['action']]);
    }

    public function discard(WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN);

        return $this->attempt(fn () => $this->definitions->discardDraft($definition), 'Draft discarded.');
    }

    public function rollback(Request $request, WorkflowDefinition $definition, WorkflowVersion $version): RedirectResponse
    {
        $this->allow(WorkflowPermissions::PUBLISH, 'version.rollback');
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(function () use ($request, $definition, $version, $d) {
            $v = $this->definitions->rollback($definition, $version, $request->user(), $d['reason']);

            return $v->status === 'published' ? "Version {$version->version} is back in force as version {$v->version}." : "A copy of version {$version->version} was sent for review as version {$v->version}.";
        }, 'Rolled back.');
    }

    public function compare(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['a' => ['required', 'uuid'], 'b' => ['required', 'uuid']]);
        $a = $definition->versions()->where('uuid', $d['a'])->firstOrFail();
        $b = $definition->versions()->where('uuid', $d['b'])->firstOrFail();

        return response()->json(['a' => $a->version, 'b' => $b->version] + $this->definitions->compare($a, $b));
    }

    public function export(WorkflowDefinition $definition): StreamedResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'definition.export');
        $payload = $this->definitions->export($definition);

        return response()->streamDownload(fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            Str::slug($definition->name) . '.workflow.json', ['Content-Type' => 'application/json']);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'definition.import');
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $payload = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($payload)) {
            return back()->with('error', 'The file is not valid JSON.');
        }

        return $this->attempt(function () use ($payload) {
            $d = $this->definitions->import($payload);

            return redirect()->route('workflow.definitions.designer', $d->uuid)->with('success', 'Imported as a draft. Named people were cleared: choose who each step goes to, then publish.');
        }, 'Imported.');
    }

    public function migrate(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $this->allow(WorkflowPermissions::MANAGE, 'instance.migrate');
        $d = $request->validate(['instances' => ['required', 'array', 'min:1', 'max:200'], 'instances.*' => ['uuid'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->attempt(function () use ($request, $definition, $d) {
            $r = $this->definitions->migrate($definition, $d['instances'], $request->user(), $d['reason']);
            $msg = count($r['moved']) . ' request(s) moved to the published version.';
            if ($r['refused']) {
                $msg .= ' Not moved: ' . collect($r['refused'])->map(fn ($why, $ref) => "{$ref} ({$why})")->implode('; ');
            }

            return $msg;
        }, 'Migrated.');
    }

    public function templates(): Response
    {
        $this->allow(WorkflowPermissions::DESIGN);
        $installed = WorkflowDefinition::query()->pluck('key')->all();

        return Inertia::render('Modules/Workflow/Templates', [
            'templates' => collect(TemplateLibrary::everything())->map(fn ($t, $k) => ['key' => $k, 'name' => $t['name'], 'description' => $t['description'], 'category' => $t['category'],
                'module' => $t['module'] ?? 'generic', 'steps' => count($t['graph']['nodes']), 'forms' => count($t['forms'] ?? []),
                'installed' => collect($installed)->contains(fn ($i) => $i === $t['key'] || str_starts_with($i, $t['key'] . '_')),
                'requires' => collect(TemplateLibrary::requires($k))->map(fn ($r) => TemplateLibrary::get($r)['name'])->values()])->values(),
            'modules' => $this->modules(),
        ]);
    }

    public function installTemplate(string $key): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'template.install');

        return $this->attempt(function () use ($key) {
            $before = WorkflowDefinition::query()->pluck('id')->all();
            $d = $this->definitions->installTemplate($key);
            $also = WorkflowDefinition::query()->whereNotIn('id', $before)->whereKeyNot($d->id)->pluck('name')->all();

            return redirect()->route('workflow.definitions.designer', $d->uuid)->with('success', 'Template installed as a draft. Check who each step goes to, then publish.'
                .($also ? ' It starts other workflows, installed as drafts too — publish them first: '.implode(', ', $also).'.' : ''));
        }, 'Installed.');
    }

    private function modules(): array
    {
        return array_merge([['key' => 'generic', 'label' => 'None — a request form']], collect(app(ProviderRegistry::class)->all())->map(fn ($p) => ['key' => $p->key(), 'label' => $p->label()])->values()->all());
    }
}
