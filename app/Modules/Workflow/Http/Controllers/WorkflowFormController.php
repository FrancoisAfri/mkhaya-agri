<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowForm;
use App\Modules\Workflow\Services\FormService;
use App\Modules\Workflow\Support\Conditions;
use App\Modules\Workflow\Support\FormLibrary;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reusable, versioned forms (PRS §6). */
class WorkflowFormController extends WorkflowController
{
    public function __construct(private readonly FormService $forms)
    {
    }

    public function index(): Response
    {
        $this->allow(WorkflowPermissions::DESIGN);

        return Inertia::render('Modules/Workflow/Forms', [
            'forms' => WorkflowForm::query()->with('currentVersion')->withCount('versions')->orderBy('name')->get()->map(fn ($f) => [
                'uuid' => $f->uuid, 'name' => $f->name, 'description' => $f->description, 'version' => $f->currentVersion?->version,
                'fields' => count($f->currentVersion?->fields ?? []), 'versions' => $f->versions_count, 'updated' => $f->updated_at?->toIso8601String()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'form.create');
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(function () use ($d) {
            $form = $this->forms->create($d['name'], $d['description'] ?? null, [['key' => 'details', 'label' => 'Details', 'type' => 'textarea', 'required' => true]]);

            return redirect()->route('workflow.forms.show', $form->uuid)->with('success', 'Form created.');
        }, 'Created.');
    }

    /** Ready-made forms that ship with the system; installing one gives the company its own editable copy. */
    public function library(): Response
    {
        $this->allow(WorkflowPermissions::DESIGN);
        $have = WorkflowForm::query()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        return Inertia::render('Modules/Workflow/FormLibrary', [
            'forms' => collect(FormLibrary::everything())->map(fn ($f) => ['key' => $f['key'], 'name' => $f['name'], 'category' => $f['category'],
                'description' => $f['description'], 'source' => $f['source'], 'fields' => array_map(fn ($x) => ['label' => $x['label'], 'type' => FormService::TYPES[$x['type']] ?? $x['type'],
                    'required' => ! empty($x['required'])], $f['fields']),
                'installed' => in_array(mb_strtolower($f['name']), $have, true)])->values(),
        ]);
    }

    public function installFromLibrary(string $key): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'form.install');
        $item = FormLibrary::get($key);
        abort_unless($item, 404);

        return $this->attempt(function () use ($item) {
            $form = $this->forms->create($item['name'], $item['description'], $item['fields']);

            return redirect()->route('workflow.forms.show', $form->uuid)->with('success', "\"{$item['name']}\" added to your forms. Change it as you need, then use it in a workflow.");
        }, 'Added.');
    }

    public function show(WorkflowForm $form): Response
    {
        $this->allow(WorkflowPermissions::DESIGN);

        return Inertia::render('Modules/Workflow/FormBuilder', [
            'form' => ['uuid' => $form->uuid, 'name' => $form->name, 'description' => $form->description, 'version' => $form->currentVersion?->version,
                'fields' => $form->currentVersion?->fields ?? []],
            'versions' => $form->versions()->orderByDesc('version')->get(['version', 'created_at'])->map(fn ($v) => ['version' => $v->version, 'at' => $v->created_at?->toIso8601String()]),
            'types' => FormService::TYPES, 'operators' => Conditions::OPERATORS, 'patterns' => array_keys(Conditions::PATTERNS),
            'can' => ['ai' => (bool) app(WorkflowSettings::class)->get('ai_enabled')],
        ]);
    }

    public function saveVersion(Request $request, WorkflowForm $form): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'form.save');
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'fields' => ['required', 'array', 'min:1', 'max:200']]);

        return $this->attempt(function () use ($form, $d) {
            $form->update(['name' => $d['name'], 'description' => $d['description'] ?? null]);
            $v = $this->forms->saveVersion($form, $d['fields']);

            return "Saved as version {$v->version}. Workflows already published keep the version they were published with.";
        }, 'Saved.');
    }
}
