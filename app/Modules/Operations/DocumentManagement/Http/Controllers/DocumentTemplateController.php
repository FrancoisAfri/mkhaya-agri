<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentTemplate;
use App\Modules\Operations\DocumentManagement\Services\GenerationService;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Operations\DocumentManagement\Support\MergeFields;
use App\Modules\Operations\ProjectManagement\Support\ContractBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The template library, and generating documents from it.
 *
 * Template bodies are sanitised on the way in with the same allow-list the contract module uses.
 * An administrator writes these, which is not the same as trusting them: the body is rendered for
 * everybody who generates from it, and one pasted from a web page brings whatever was in it.
 */
class DocumentTemplateController extends Controller
{
    public function __construct(private readonly GenerationService $generation)
    {
    }

    public function index(): Response
    {
        $this->authorize('manageSettings', Document::class);

        return Inertia::render('Modules/DocumentManagement/Pages/Templates', [
            'templates' => DocumentTemplate::with('category')->orderBy('name')->get()
                ->map(fn (DocumentTemplate $t) => [
                    'uuid' => $t->uuid,
                    'name' => $t->name,
                    'description' => $t->description,
                    'subject_relation' => $t->subject_relation,
                    'category' => $t->category?->name,
                    'category_uuid' => $t->category?->uuid,
                    'folder_uuid' => $t->folder?->uuid,
                    'filename_pattern' => $t->filename_pattern,
                    'is_active' => $t->is_active,
                    'body' => $t->body,
                    // Said here so the editor can show a typo before anybody generates from it.
                    'unknown_fields' => $t->unknownFields(),
                ])->all(),
            'relations' => collect(DocumentRelations::available())
                ->map(fn ($d, $k) => ['value' => $k, 'label' => $d['label']])->values()->all(),
            'categories' => DocumentCategory::usable()->get()
                ->map(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name])->all(),
            'folders' => DocumentFolder::orderBy('path')->get()
                ->map(fn ($f) => ['uuid' => $f->uuid, 'name' => $f->name, 'depth' => $f->depth])->all(),
            // The field list an author picks from, per subject.
            'fields' => collect(DocumentRelations::available())->keys()
                ->mapWithKeys(fn ($relation) => [$relation => MergeFields::availableFor($relation)])
                ->put('', MergeFields::companyFields())
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $data = $this->validated($request);

        DocumentTemplate::create($data + ['created_by' => Auth::id()]);

        return back()->with('success', __('document_management.templates.created'));
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $template->update($this->validated($request));

        return back()->with('success', __('document_management.templates.updated'));
    }

    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $template->delete();

        return back()->with('success', __('document_management.templates.deleted'));
    }

    /**
     * What the document would say, before anybody commits to it.
     *
     * JSON rather than a page, because it is asked for while somebody is choosing a record and
     * changes as they change it.
     */
    public function preview(Request $request, DocumentTemplate $template): JsonResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate(['subject_id' => ['nullable', 'integer']]);

        return response()->json($this->generation->preview($template, $this->subject($template, $data['subject_id'] ?? null)));
    }

    public function generate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'subject_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:240'],
            'folder' => ['nullable', 'string'],
            'allow_missing' => ['nullable', 'boolean'],
        ]);

        $result = $this->generation->generate($template, $this->subject($template, $data['subject_id'] ?? null), [
            'title' => $data['title'] ?? null,
            'folder_id' => filled($data['folder'] ?? null)
                ? DocumentFolder::where('uuid', $data['folder'])->value('id')
                : null,
            'allow_missing' => (bool) ($data['allow_missing'] ?? false),
        ]);

        return $result['document']
            ? redirect()
                ->route('document-management.documents.show', $result['document']->uuid)
                ->with('success', __('document_management.templates.generated'))
            : back()->with('error', $result['error']);
    }

    /** The record a template is being generated about. */
    private function subject(DocumentTemplate $template, ?int $id): ?\Illuminate\Database\Eloquent\Model
    {
        $definition = DocumentRelations::definition((string) $template->subject_relation);

        return $definition && $id ? $definition['model']::find($id) : null;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'subject_relation' => ['nullable', Rule::in(DocumentRelations::names())],
            'category' => ['nullable', 'string'],
            'folder' => ['nullable', 'string'],
            'filename_pattern' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:400000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'subject_relation' => $data['subject_relation'] ?? null,
            'category_id' => filled($data['category'] ?? null)
                ? DocumentCategory::where('uuid', $data['category'])->value('id') : null,
            'folder_id' => filled($data['folder'] ?? null)
                ? DocumentFolder::where('uuid', $data['folder'])->value('id') : null,
            'filename_pattern' => $data['filename_pattern'] ?? null,
            // The same allow-list the contract module uses. Written by an administrator is not the
            // same as safe: a body pasted from a web page brings whatever was in it.
            'body' => ContractBody::sanitize($data['body'] ?? null),
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
