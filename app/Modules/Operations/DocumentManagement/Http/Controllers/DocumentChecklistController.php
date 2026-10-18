<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklist;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistItem;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistTemplate;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistTemplateItem;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\ChecklistService;
use App\Modules\Operations\DocumentManagement\Services\DocumentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Packs of documents: the rule, and the cases it is applied to.
 *
 * Both on one screen, because they are read together — "what does a supplier pack require" is asked
 * while looking at a supplier pack that is missing something.
 */
class DocumentChecklistController extends Controller
{
    use \App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;

    public function __construct(
        private readonly ChecklistService $checklists,
        private readonly DocumentRequestService $requests,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        /*
         * Reconciled on the way in rather than by a scheduled job. Somebody opening this has just
         * filed something, or is about to ask why it does not show — and a pack that is only
         * correct after a nightly run is a pack nobody trusts during the day.
         */
        $checklists = DocumentChecklist::query()
            ->with(['folder', 'items.category', 'items.document'])
            ->orderByRaw('completed_at is not null')
            ->orderByRaw('due_at is null, due_at')
            ->limit(100)
            ->get();

        foreach ($checklists as $checklist) {
            $this->checklists->reconcile($checklist);
        }

        return Inertia::render('Modules/DocumentManagement/Pages/Checklists', [
            'checklists' => $checklists->map(function (DocumentChecklist $checklist) {
                $checklist = $checklist->fresh(['folder', 'items.category', 'items.document']);

                return [
                    'uuid' => $checklist->uuid,
                    'name' => $checklist->name,
                    'subject' => $checklist->subject,
                    'folder' => $checklist->folder?->name,
                    'folder_uuid' => $checklist->folder?->uuid,
                    'due_at' => $checklist->due_at?->toFormattedDateString(),
                    'overdue' => $checklist->isOverdue(),
                    'completed_at' => $checklist->completed_at?->toFormattedDateString(),
                    'progress' => $this->checklists->progress($checklist),
                    'items' => $checklist->items->map(fn (DocumentChecklistItem $item) => [
                        'uuid' => $item->uuid,
                        'title' => $item->title,
                        'description' => $item->description,
                        'category' => $item->category?->name,
                        'required' => (bool) $item->is_required,
                        'satisfied' => $item->isSatisfied(),
                        'document' => $item->document?->uuid,
                        'document_title' => $item->document?->title,
                        'requested' => $item->request_id !== null,
                    ])->values(),
                ];
            })->values(),

            'templates' => DocumentChecklistTemplate::query()
                ->where('is_active', true)
                ->with('items.category')
                ->orderBy('name')
                ->get()
                ->map(fn (DocumentChecklistTemplate $template) => [
                    'uuid' => $template->uuid,
                    'name' => $template->name,
                    'description' => $template->description,
                    'items' => $template->items->map(fn (DocumentChecklistTemplateItem $item) => [
                        'title' => $item->title,
                        'category' => $item->category?->name,
                        'required' => (bool) $item->is_required,
                    ])->values(),
                ])->values(),

            'folders' => DocumentFolder::query()->whereNull('archived_at')->orderBy('path')
                ->get()->map(fn (DocumentFolder $f) => ['uuid' => $f->uuid, 'name' => $f->name, 'depth' => (int) $f->depth]),

            'categories' => DocumentCategory::query()->orderBy('name')
                ->get()->map(fn (DocumentCategory $c) => ['uuid' => $c->uuid, 'name' => $c->name]),

            'people' => $this->tenantPeople($request),

            'can_manage' => $request->user()?->can('manageFolders', Document::class) ?? false,
        ]);
    }

    /** Writes the rule. */
    public function storeTemplate(Request $request): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.category' => ['nullable', 'string'],
            'items.*.required' => ['nullable', 'boolean'],
        ]);

        $template = DocumentChecklistTemplate::create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        foreach (array_values($data['items']) as $index => $item) {
            DocumentChecklistTemplateItem::create([
                'template_id' => $template->id,
                'title' => $item['title'],
                'category_id' => filled($item['category'] ?? null)
                    ? DocumentCategory::where('uuid', $item['category'])->value('id')
                    : null,
                'is_required' => (bool) ($item['required'] ?? true),
                'sort_order' => $index,
            ]);
        }

        return back()->with('success', __('document_management.checklists.template_created'));
    }

    /**
     * Applies the rule to one case, or to several at once.
     *
     * People are picked rather than typed, because a pack is nearly always about somebody already
     * on the system and a typed name is one spelling away from being unfindable. Picking several
     * starts a pack each — onboarding three people in a week is one act, not three visits to this
     * screen.
     *
     * The free-text subject stays alongside it, because a pack is as often about a supplier, a
     * tender or a property as it is about a person, and none of those are in the staff list.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'template' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:200'],
            'people' => ['nullable', 'array'],
            'people.*' => ['integer'],
            'subject' => ['nullable', 'string', 'max:200'],
            'folder' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
        ]);

        $people = array_values(array_unique(array_map('intval', $data['people'] ?? [])));

        // A pack about nobody is a pack nobody will look for again.
        if ($people === [] && blank($data['subject'] ?? null)) {
            return back()->with('error', __('document_management.checklists.needs_a_subject'));
        }

        $template = DocumentChecklistTemplate::where('uuid', $data['template'])->firstOrFail();

        $folderId = filled($data['folder'] ?? null)
            ? DocumentFolder::where('uuid', $data['folder'])->value('id')
            : null;

        $common = [
            'name' => $data['name'] ?? null,
            'folder_id' => $folderId,
            'due_at' => $data['due_at'] ?? null,
        ];

        $started = 0;

        // Resolved through the tenant picker so a pack cannot be started against somebody in
        // another company by posting their id.
        $names = collect($this->tenantPeople($request))->keyBy('id');

        foreach ($people as $userId) {
            if (! $names->has($userId)) {
                continue;
            }

            $this->checklists->start($template, $common + ['subject' => $names[$userId]['name']]);
            $started++;
        }

        if (filled($data['subject'] ?? null)) {
            $this->checklists->start($template, $common + ['subject' => $data['subject']]);
            $started++;
        }

        /*
         * Nothing was started, which is not success. It happens when every id posted was filtered
         * out above — an id from another company, or somebody who has since left — and reporting
         * "started 0 packs" as good news would leave the person believing the pack exists.
         */
        if ($started === 0) {
            return back()->with('error', __('document_management.checklists.needs_a_subject'));
        }

        return back()->with('success', __('document_management.checklists.started_n', ['count' => $started]));
    }

    /** Attaches a particular document to an item, or takes it off. */
    public function satisfy(Request $request, DocumentChecklistItem $item): RedirectResponse
    {
        $this->authorize('create', Document::class);

        if ($request->boolean('clear')) {
            $this->checklists->clear($item);

            return back()->with('success', __('document_management.checklists.cleared'));
        }

        $data = $request->validate(['document' => ['required', 'string']]);

        $document = Document::where('uuid', $data['document'])->first();

        if (! $document || ! $request->user()->can('view', $document)) {
            return back()->with('error', __('document_management.access.not_found'));
        }

        $this->checklists->satisfyWith($item, $document);

        return back()->with('success', __('document_management.checklists.satisfied'));
    }

    /**
     * Turns everything outstanding into requests, in one act.
     *
     * The reason a checklist and a request queue belong in the same module. Asking for six missing
     * documents one at a time is where the sixth gets forgotten.
     */
    public function chase(Request $request, DocumentChecklist $checklist): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'people' => ['required', 'array', 'min:1'],
            'people.*' => ['integer'],
            'due_at' => ['nullable', 'date'],
        ]);

        $asked = 0;

        foreach ($this->checklists->outstanding($checklist) as $item) {
            $created = $this->requests->ask($item->title, $data['people'], [
                'description' => $item->description,
                'folder_id' => $checklist->folder_id,
                'category_id' => $item->category_id,
                'due_at' => $data['due_at'] ?? $checklist->due_at?->toDateString(),
            ]);

            // Remembered on the item so the screen can say "already asked for" rather than
            // offering to ask again and quietly making a duplicate.
            if ($created !== []) {
                $item->forceFill(['request_id' => $created[0]->id])->save();
                $asked++;
            }
        }

        return back()->with('success', __('document_management.checklists.chased', ['count' => $asked]));
    }

    public function destroy(DocumentChecklist $checklist): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        DocumentChecklistItem::where('checklist_id', $checklist->id)->delete();
        $checklist->delete();

        return back()->with('success', __('document_management.checklists.deleted'));
    }
}
