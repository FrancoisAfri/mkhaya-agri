<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\ApprovalService;
use App\Modules\Operations\DocumentManagement\Services\DocumentSearch;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\FileTypeGuard;
use App\Modules\Operations\DocumentManagement\Services\ExpiryService;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\LockService;
use App\Modules\Operations\DocumentManagement\Services\SignatureService;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Modules\Operations\DocumentManagement\Services\ArchiveImporter;
use App\Modules\Operations\DocumentManagement\Services\ArchiveBrowser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Validation\Rule;
use Inertia\Response as InertiaResponse;
use Illuminate\Support\Facades\Log;

/**
 * The document repository.
 *
 * Serving a file is the part of this worth reading carefully. Every byte leaves through `stream()`,
 * which sets `Content-Disposition` and `X-Content-Type-Options: nosniff` on every response — a
 * document served inline from the application's own domain is same-origin with the application, so
 * an uploaded file that a browser will execute is stored cross-site scripting. The upload guard is
 * the first line against that and these headers are the second, because one of them will eventually
 * be wrong.
 */
class DocumentController extends Controller
{
    use \App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;
    use \App\Modules\Operations\DocumentManagement\Concerns\ServesDocumentBytes;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly FileTypeGuard $guard,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Document::class);

        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status', Document::STATUS_ACTIVE);
        $user = Auth::user();

        /*
         * One query builder for the search box and the filter panel alike.
         *
         * Keeping them separate would mean two definitions of what a folder filter means and two
         * places for the visibility scope to be forgotten — and the second is the one that matters.
         */
        $documents = app(DocumentSearch::class)->query($user, $this->searchFilters($request))
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Modules/DocumentManagement/Pages/Index', [
            'documents' => $documents->through(fn (Document $d) => $this->row($d)),
            'folders'   => $this->folderTree(),

            /*
             * Who a folder can be given to. Sent with the page rather than fetched when the
             * permissions dialog opens, because these four lists are small, shared by every folder
             * on the tree, and would otherwise be four requests deep into a modal.
             *
             * Groups lead: a group is the answer that survives somebody joining or leaving, where a
             * list of names has to be revisited every time either happens.
             */
            'permission_subjects' => [
                'groups' => \App\Modules\Operations\DocumentManagement\Models\DocumentGroup::query()
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($g) => ['id' => (int) $g->id, 'name' => $g->name])->values(),
                'roles' => \Spatie\Permission\Models\Role::query()->orderBy('name')->pluck('name'),
                'departments' => $this->tenantDepartments($request),
                'people' => $this->tenantPeople($request),
            ],
            'categories' => $this->categoryList(),
            'relations' => $this->relationList(),
            'confidentiality_levels' => Document::CONFIDENTIALITY_LEVELS,
            // What is lapsing, counted through the same visibility scope as the listing so the
            // number and the rows agree.
            'expiry'    => app(ExpiryService::class)->summary(),
            // What is waiting on this person specifically. A queue showing everybody's approvals
            // is a queue nobody treats as theirs.
            'awaiting_me' => $user ? app(ApprovalService::class)->waitingOn($user)
                ->with('request.document')->get()
                ->map(fn (DocumentApprovalAssignment $a) => [
                    'uuid' => $a->uuid,
                    'document_uuid' => $a->request?->document?->uuid,
                    'title' => $a->request?->document?->title,
                    'step' => $a->step_name,
                    'due_at' => $a->due_at?->toDateString(),
                    'is_overdue' => $a->isOverdue(),
                ])->values()->all() : [],
            'filters'   => [
                'q' => $search, 'status' => $status, 'folder' => $request->query('folder'),
                'expiring' => $request->boolean('expiring'),
            ],

            /*
             * Which slice of the library this is.
             *
             * Every one of these screens rendered as "Documents" with an upload box and the whole
             * folder tree, so a person sent to Awaiting approval saw a page identical to the one
             * they came from and reasonably concluded the link was broken. The filter was working;
             * the page simply never said what it was showing.
             */
            'view'      => $this->currentView($request),
            'settings'  => $this->settingsPayload(),
            // Counted through the same scope as the listing. A total that includes documents the
            // page cannot show is both wrong and a disclosure in itself — "8 confidential" tells
            // someone there are eight, which is the first thing the flag was meant to withhold.
            'totals'    => [
                'active'       => Document::visibleTo($user)->where('status', Document::STATUS_ACTIVE)->count(),
                'archived'     => Document::visibleTo($user)->where('status', Document::STATUS_ARCHIVED)->count(),
                'confidential' => Document::visibleTo($user)->where('is_confidential', true)->count(),
                'legal_hold'   => Document::visibleTo($user)->where('is_legally_sensitive', true)->count(),
            ],
        ]);
    }

    public function show(Document $document): InertiaResponse
    {
        /*
         * A refusal that offers a way forward.
         *
         * The repository hides what somebody cannot open, which leaves exactly one way for them to
         * meet a document they have no access to: a colleague sends a link. Answering that with a
         * bare 403 sends them to ask in a corridor, and whatever grant follows is recorded nowhere.
         *
         * The page below says what they are looking at — its title and nothing else — and offers to
         * ask. A title is the smallest thing that lets somebody say which document they mean, and
         * it is already disclosed by the fact that they were sent the link.
         */
        if ($request = $this->accessRefusal($document)) {
            return $request;
        }

        $this->authorize('view', $document);

        $document->load(['versions', 'folder', 'currentVersion', 'category', 'links']);

        // A read is recorded, not just a write. "Who has opened this?" is the first question asked
        // of a confidential document and it cannot be answered retrospectively.
        $this->documents->record($document, DocumentEvent::VIEWED);

        return Inertia::render('Modules/DocumentManagement/Pages/Show', [
            'document' => $this->row($document) + [
                'notes'    => $document->notes,
                'sensitivity_note' => $document->sensitivity_note,

                // The full metadata set, which the listing only carries a summary of.
                'department_unit_id' => $document->department_unit_id,
                'owner' => $this->userName($document->owner_id),
                'uploaded_by' => $this->userName($document->created_by),
                'uploaded_at' => $document->created_at?->toDayDateTimeString(),
                'document_date' => $document->document_date?->toDateString(),
                'effective_date' => $document->effective_date?->toDateString(),
                'in_force' => $document->isInForce(),
                'category_uuid' => $document->category?->uuid,

                // Grouped by relation, which is how the detail screen lays them out — "Supplier:
                // Acme" rather than a flat list of things.
                'links' => $document->linksByRelation(),
                'approval_status' => $document->approval_status,
                'published_at' => $document->published_at?->toDayDateTimeString(),
                'blocks_when_expired' => (bool) $document->blocks_when_expired,
                'expired_at' => $document->expired_at?->toDayDateTimeString(),
                /*
                 * `versions_list`, not `versions`.
                 *
                 * This array sat under the same key as the count that `row()` already returns, and
                 * the two were joined with `+`, which keeps the left operand for a duplicate key.
                 * The count won and the list was discarded in silence, so the versions table drew
                 * one empty row — `v-for` over the number 1 — on every document in the system.
                 */
                /*
                 * Whether the page can show the file rather than only offer to fetch it, and
                 * whether there is anything to look inside. Both answered from the current
                 * version, because that is what the panels show.
                 */
                'current_version_uuid' => $document->currentVersion?->uuid,
                'is_archive' => app(ArchiveBrowser::class)->isBrowsable($document->currentVersion?->extension),

                'versions_list' => $document->versions->map(fn (DocumentVersion $v) => [
                    'uuid'        => $v->uuid,
                    'version'     => $v->version,
                    // 1.0, 1.1, 2.0 — what people call it, as opposed to what orders it.
                    'label'       => $v->label(),
                    'name'        => $v->original_name,
                    'size'        => $v->humanSize(),
                    'mime'        => $v->mime,
                    'change_note' => $v->change_note,
                    'uploaded_by' => $this->userName($v->uploaded_by),
                    'uploaded_at' => $v->created_at?->toDayDateTimeString(),
                    'previewable' => $this->guard->canPreview($v->mime),
                    'is_current'  => $v->id === $document->current_version_id,
                    // Said explicitly, so a restoration is not presented as an ordinary upload.
                    'restored_from' => $v->restoredFrom?->label(),
                ]),
            ],
            'folders' => $this->folderTree(),
            'categories' => $this->categoryList(),
            'relations' => $this->relationList(),
            'confidentiality_levels' => Document::CONFIDENTIALITY_LEVELS,
            'approval' => $this->approvalPayload($document),
            // Read from the envelope each time rather than from a column that drifts.
            'signature' => app(SignatureService::class)->progress($document),
            /*
             * Who an approval could be handed to, and who a comment can name.
             *
             * Anyone active in the company rather than a filtered list: delegation is for the
             * exception — somebody on leave, somebody covering — and a list that guessed at who
             * qualifies would exclude exactly the person needed.
             *
             * "In the company" said explicitly rather than left to the User model's global scope,
             * which steps aside for a platform administrator by design. Relying on it here listed
             * every tenant's staff to the one account most likely to be looking at several.
             */
            'people' => $this->tenantPeople(),
            'workflows' => DocumentApprovalWorkflow::where('is_active', true)->get()
                ->map(fn ($w) => ['uuid' => $w->uuid, 'name' => $w->name])->all(),
            'history' => $document->events()->limit(100)->get()->map(fn (DocumentEvent $e) => [
                'action' => $e->action,
                'at'     => $e->created_at?->toDayDateTimeString(),
                'user'   => $this->userName($e->user_id),
                'detail' => $e->detail,
            ]),

            /*
             * External links. The token is not here and cannot be — only its hash is stored — so
             * this is what a link looks like to the people inside: who it went to, when it lapses,
             * and how often it has been opened.
             */
            'shares' => \App\Modules\Operations\DocumentManagement\Models\DocumentShare::query()
                ->where('document_id', $document->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($share) => [
                    'uuid' => $share->uuid,
                    'recipient_email' => $share->recipient_email,
                    'note' => $share->note,
                    'expires_at' => $share->expires_at?->toFormattedDateString(),
                    'allow_download' => $share->allow_download,
                    'max_downloads' => $share->max_downloads,
                    'download_count' => $share->download_count,
                    'view_count' => $share->view_count,
                    'last_accessed_at' => $share->last_accessed_at?->toDayDateTimeString(),
                    'state' => $share->state(),
                    'created_by' => $this->userName($share->created_by),
                ]),
            /*
             * The assistant, and why it is not available where it is not. A bare "unavailable"
             * invites somebody to keep clicking; the reason tells them whether to switch something
             * on, run OCR, or wait for next month's allowance.
             */
            /*
             * Whether this document's contents are searchable, and why not where they are not.
             *
             * Shown on the document rather than only in a list, because the person who can do
             * something about a scan is usually the person looking at it — and until it is said
             * somewhere, the only way to discover a document is unsearchable is to search for a
             * phrase you know is in it and get nothing back.
             */
            'extraction' => (function () use ($document) {
                $extraction = $document->currentExtraction;

                if (! $extraction) {
                    return ['status' => 'pending', 'needs_attention' => false, 'words' => 0];
                }

                return [
                    'status' => $extraction->status,
                    'needs_attention' => $extraction->needsAttention(),
                    'words' => (int) $extraction->word_count,
                    'pages' => $extraction->page_count,
                ];
            })(),

            /*
             * The extra fields this kind of document carries, with whatever has been recorded.
             * Sent as a definition rather than as rendered values so the form can be the same
             * component as the display, and a required field can say so before it is refused.
             */
            'custom_fields' => (function () use ($document) {
                $rules = app(\App\Modules\Operations\DocumentManagement\Services\CategoryRules::class);
                $values = $document->custom_fields ?? [];

                return $rules->fieldsFor($document->category)->map(fn ($field) => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'options' => $field->options ?? [],
                    'hint' => $field->hint,
                    'required' => (bool) $field->is_required,
                    'value' => $values[$field->key] ?? null,
                ])->values();
            })(),

            'ai' => $this->aiPanel($document),

            /*
             * Who this document has been shared with inside the organisation. Separate from the
             * external links because they answer different questions — "who here can see this" and
             * "what has left the building" — and a single list mixing them answers neither.
             */
            'grants' => app(\App\Modules\Operations\DocumentManagement\Services\InternalShareService::class)
                ->grantsFor($document)
                ->map(fn ($grant) => [
                    'uuid' => $grant->uuid,
                    'subject_type' => $grant->subject_type,
                    'subject_name' => $grant->subject_name,
                    'can_download' => (bool) $grant->can_download,
                    'expires_at' => $grant->expires_at?->toFormattedDateString(),
                    'expired' => $grant->hasExpired(),
                    'note' => $grant->note,
                    'granted_by' => $this->userName($grant->granted_by),
                ]),

            // The subjects a share can be given to, resolved here so the picker cannot offer a
            // department or role this company does not have.
            'share_subjects' => [
                'roles' => \Spatie\Permission\Models\Role::query()->orderBy('name')->pluck('name'),
                // Scoped through the shared resolver rather than `when(CurrentCompany)`: that
                // leaves the list unfiltered for a platform administrator, which is the one account
                // that would be shown several companies' departments side by side.
                'departments' => $this->tenantDepartments(),
            ],

            /*
             * What this person may actually do about a copy.
             *
             * Sent rather than assumed, because the page previously offered a Download button to
             * everybody and let the policy answer with a 403. Being refused after clicking teaches
             * nothing — least of all that there is a way to ask.
             */
            'downloading' => (function () use ($document) {
                $user = auth()->user();

                return [
                    'allowed' => $user?->can('download', $document) ?? false,
                    'can_request' => $user?->can('requestDownload', $document) ?? false,
                    'can_set_policy' => $user?->can('setDownloadPolicy', $document) ?? false,
                    // Marked read-only here, or somewhere above it. Distinguished from a plain
                    // refusal so the page can say why rather than only that.
                    'restricted' => (bool) $document->download_disabled
                        || app(FolderAccess::class)->downloadsBlocked($document->folder),
                    'by_folder' => app(FolderAccess::class)->downloadsBlocked($document->folder),
                    'requested' => $user !== null && \App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest::query()
                        ->where('document_id', $document->id)
                        ->where('requested_by', $user->id)
                        ->where('kind', \App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest::KIND_DOWNLOAD)
                        ->pending()
                        ->exists(),
                ];
            })(),

            /*
             * The conversation about this document. Sent whole rather than fetched separately: it
             * is short, it is the thing people scroll to, and a second request to load it would put
             * a spinner where the discussion should be.
             */
            // Where else this document is filed. Named rather than counted: "also in Contracts and
            // Induction packs" is the useful form of the answer.
            'shortcuts' => \App\Modules\Operations\DocumentManagement\Models\DocumentShortcut::query()
                ->where('document_id', $document->id)
                ->with('folder')
                ->get()
                ->map(fn ($shortcut) => [
                    'uuid' => $shortcut->uuid,
                    'folder' => $shortcut->folder?->name,
                    'folder_uuid' => $shortcut->folder?->uuid,
                ])
                ->filter(fn ($row) => $row['folder'] !== null)
                ->values(),

            'comments' => app(\App\Modules\Operations\DocumentManagement\Services\CommentService::class)
                ->threadsFor($document),

            /*
             * Who has this checked out. Sent even when nobody does, because the page has to be able
             * to offer checking it out — and sent as a name rather than an id, since "locked" with
             * nobody attached to it is the version of this feature people hate.
             */
            'lock' => (function () use ($document) {
                $locks = app(LockService::class);
                $holder = $locks->holder($document);
                $user = auth()->user();

                return [
                    'held' => $holder !== null,
                    'mine' => $locks->heldBy($document, $user),
                    'by' => $holder ? $this->userName($holder['user_id']) : null,
                    'since' => $holder && $holder['since'] ? \Illuminate\Support\Carbon::parse($holder['since'])->diffForHumans() : null,
                    'until' => $holder && $holder['until'] ? \Illuminate\Support\Carbon::parse($holder['until'])->toDayDateTimeString() : null,
                    'note' => $holder['note'] ?? null,
                    'can_break' => $holder !== null && ($user?->can('breakLock', $document) ?? false),
                    'hours' => $locks->hours(),
                ];
            })(),

            'sharing' => [
                'enabled' => (bool) DocumentSetting::current()->sharing_enabled,
                'max_days' => (int) (DocumentSetting::current()->share_max_days ?? 30),
                'can_share' => auth()->user()?->can('share', $document) ?? false,
            ],
        ]);
    }

    /**
     * Takes one or more files.
     *
     * Each file is judged on its own: one refusal does not throw away the others, because losing a
     * nine-file upload to a single bad file is how people learn to hate a document system.
     */
    /**
     * Upload a folder, by uploading a zip of it.
     *
     * A browser cannot hand over a directory in a form post, and dragging four hundred files in one
     * at a time is not a workflow. The structure inside the archive is rebuilt rather than
     * flattened: a year's project correspondence arriving as one heap of four hundred documents is
     * not the same as arriving as the folders it was filed in.
     *
     * Every file inside still goes through the ordinary upload path — same extensions, same size
     * cap, same quotas, same numbering, same audit trail. See ArchiveImporter for why that matters
     * more than any convenience here.
     */
    public function storeArchive(Request $request, ArchiveImporter $importer): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $settings = DocumentSetting::current();

        $request->validate([
            // `mimes:zip` rather than trusting the extension: the browser-declared type on a zip is
            // one of half a dozen strings depending on the operating system that made it.
            'archive' => ['required', 'file', 'mimes:zip', 'max:' . ($settings->max_file_size_mb * 1024)],
            'folder'  => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_confidential' => ['nullable', 'boolean'],
        ]);

        $folderId = $request->filled('folder')
            ? DocumentFolder::where('uuid', $request->input('folder'))->value('id')
            : null;

        try {
            $result = $importer->import($request->file('archive'), $folderId, [
                'description' => $request->input('description'),
                'is_confidential' => $request->boolean('is_confidential'),
            ]);
        } catch (\RuntimeException $e) {
            // A refusal the person can act on — too many entries, too large, unreadable — rather
            // than a 500 that tells them only that something went wrong.
            return back()->withErrors(['archive' => $e->getMessage()]);
        }

        return back()
            ->with('success', __('document_management.archive.done', [
                'files' => $result['imported'],
                'folders' => $result['folders'],
            ]))
            // Named, not counted: "12 skipped" does not say which twelve, or why.
            ->with('archiveSkipped', $result['skipped']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $settings = DocumentSetting::current();

        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:' . max(1, $settings->max_files_per_upload)],
            'files.*' => ['required', 'file'],
            'folder'  => ['nullable', 'string'],
            'title'   => ['nullable', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes'       => ['nullable', 'string', 'max:5000'],
            'is_confidential'      => ['nullable', 'boolean'],
            'is_legally_sensitive' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'document_type' => ['nullable', 'string', 'max:120'],
            'department_unit_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'document_date' => ['nullable', 'date'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'confidentiality' => ['nullable', Rule::in(Document::CONFIDENTIALITY_LEVELS)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'links' => ['nullable', 'array'],
            'links.*.relation' => ['required', 'string', Rule::in(DocumentRelations::names())],
            'links.*.id' => ['required', 'integer'],
            'blocks_when_expired' => ['nullable', 'boolean'],
            // The extra fields this kind of document carries. Validated against the category by the
            // service, which is the only place that knows what the category asks for.
            'custom_fields' => ['nullable', 'array'],
        ]);

        $folderId = $request->filled('folder')
            ? DocumentFolder::where('uuid', $request->input('folder'))->value('id')
            : null;

        $stored = 0;
        $refused = [];

        foreach ($request->file('files') as $file) {
            $result = $this->documents->store($file, [
                'folder_id'   => $folderId,
                // A title only applies to a single upload; naming ten files the same helps nobody.
                'title'       => count($request->file('files')) === 1 ? $request->input('title') : null,
                'description' => $request->input('description'),
                'notes'       => $request->input('notes'),
                'is_confidential'      => $request->boolean('is_confidential'),
                'is_legally_sensitive' => $request->boolean('is_legally_sensitive'),
            ] + $this->metadataFrom($request, single: count($request->file('files')) === 1));

            if (! $result['document']) {
                $refused[] = $file->getClientOriginalName() . ' — ' . $result['error'];

                continue;
            }

            $this->applyLinks($result['document'], $request->input('links', []));
            $stored++;
        }

        if ($stored === 0) {
            return back()->with('error', implode(' · ', $refused));
        }

        return $refused === []
            ? back()->with('success', $stored . ' document(s) uploaded.')
            : back()->with('warning', $stored . ' uploaded. ' . count($refused) . ' refused: ' . implode(' · ', $refused));
    }

    public function replace(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('replace', $document);

        $request->validate([
            'file' => ['required', 'file'],
            'change_note' => ['nullable', 'string', 'max:1000'],
            // Whether this is a new edition or a correction. Only the uploader knows.
            'major' => ['nullable', 'boolean'],
        ]);

        $result = $this->documents->replace(
            $document,
            $request->file('file'),
            $request->input('change_note'),
            $request->boolean('major'),
        );

        return $result['version']
            ? back()->with('success', 'Version ' . $result['version']->version . ' saved. Earlier versions are still available.')
            : back()->with('error', $result['error']);
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        // Somebody else has it. Editing the details while they work on the file is the smaller half
        // of the same collision, and letting it through would make the lock look advisory.
        if (app(LockService::class)->blocks($document, $request->user())) {
            return back()->with('error', __('document_management.lock.held_by_another'));
        }

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes'       => ['nullable', 'string', 'max:5000'],
            'folder'      => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'document_type' => ['nullable', 'string', 'max:120'],
            'department_unit_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'document_date' => ['nullable', 'date'],
            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'confidentiality' => ['nullable', Rule::in(Document::CONFIDENTIALITY_LEVELS)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'links' => ['nullable', 'array'],
            'links.*.relation' => ['required', 'string', Rule::in(DocumentRelations::names())],
            'links.*.id' => ['required', 'integer'],
            'blocks_when_expired' => ['nullable', 'boolean'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        $document->fill($this->metadataFrom($request, single: true));

        /*
         * The extra fields, cleaned against whatever category the document is in *after* this edit
         * — changing the category and the values in one save is the ordinary way somebody corrects
         * a misfiled document, and validating against the old category would refuse it.
         */
        if ($request->has('custom_fields')) {
            $rules = app(\App\Modules\Operations\DocumentManagement\Services\CategoryRules::class);
            $category = $document->category_id
                ? DocumentCategory::find($document->category_id)
                : null;

            $required = $rules->validate($category, $request->input('custom_fields', []));

            if (! $required['ok']) {
                return back()->with('error', __('document_management.fields.required', [
                    'fields' => implode(', ', $required['missing']),
                ]));
            }

            $document->custom_fields = $rules->clean($category, $request->input('custom_fields', []));
        }

        // Whether a lapse should hold up work elsewhere. Sent on its own from the expiry panel, so
        // it is read directly rather than through the metadata helper's "only what was sent" filter.
        if ($request->has('blocks_when_expired')) {
            $document->blocks_when_expired = $request->boolean('blocks_when_expired');
        }

        if ($request->has('links')) {
            // Sent whole, so a link removed on screen is actually removed. Replacing the set is the
            // only way a form that shows all of them can express a deletion.
            $document->links()->delete();
            $this->applyLinks($document, $request->input('links', []));
        }

        if ($request->has('folder')) {
            $data['folder_id'] = $request->filled('folder')
                ? DocumentFolder::where('uuid', $request->input('folder'))->value('id')
                : null;
        }
        unset($data['folder']);

        /*
         * What is about to change, captured before the write.
         *
         * An audit trail that records views and downloads but not edits is worse than an incomplete
         * one, because it looks complete: a document could be renamed, re-dated, re-categorised and
         * moved to another folder with nothing to show it ever happened. Renaming and moving are
         * their own events because they are the two a person actually goes looking for.
         */
        $before = $document->getOriginal();

        $document->update($data + ['updated_by' => Auth::id()]);

        $this->recordChanges($document, $before);

        return back()->with('success', 'Document updated.');
    }

    public function sensitivity(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('setSensitivity', $document);

        $data = $request->validate([
            'is_confidential'      => ['required', 'boolean'],
            'is_legally_sensitive' => ['required', 'boolean'],
            'sensitivity_note'     => ['nullable', 'string', 'max:500'],
        ]);

        $this->documents->setSensitivity(
            $document,
            (bool) $data['is_confidential'],
            (bool) $data['is_legally_sensitive'],
            $data['sensitivity_note'] ?? null,
        );

        return back()->with('success', 'Sensitivity updated and recorded.');
    }

    public function archive(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('archive', $document);

        $this->documents->archive($document, $request->input('reason'));

        return back()->with('success', 'Archived. It stays searchable and can be restored at any time.');
    }

    public function restore(Document $document): RedirectResponse
    {
        $this->authorize('restore', $document);

        $this->documents->restore($document);

        return back()->with('success', 'Restored.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $result = $this->documents->delete($document);

        return $result['ok']
            ? back()->with('success', 'Deleted. It can still be recovered until it is permanently destroyed.')
            : back()->with('error', $result['error']);
    }

    /** Destroys the document and its files. Separate permission, separate route, separate warning. */
    public function purge(Document $document): RedirectResponse
    {
        $this->authorize('purge', $document);

        $result = $this->documents->purge($document);

        return $result['ok']
            ? redirect()->route('document-management.index')->with('success', 'Permanently destroyed. The record that it existed and was destroyed is kept.')
            : back()->with('error', $result['error']);
    }

    // ── Serving bytes ────────────────────────────────────────────────────────

    /** Rendered in the browser, for the handful of types a browser can display safely. */
    /**
     * What is inside an archive, without taking the archive.
     *
     * A reseller pack arrives as one zip of forty images and somebody wants the third one.
     * Downloading the lot to find it leaves the document system knowing only that a zip was
     * fetched, and nothing about what was actually wanted — so this reads the directory, records
     * that somebody looked, and expands nothing.
     *
     * Gated on `view` rather than `download` for the same reason preview is: reading a list of
     * names is looking, not taking.
     */
    public function archiveContents(Document $document, ?DocumentVersion $version = null): JsonResponse
    {
        $this->authorize('view', $document);

        $version = $this->resolveVersion($document, $version);

        $listing = app(ArchiveBrowser::class)->listing($version);

        if ($listing['supported']) {
            $this->documents->record($document, DocumentEvent::VIEWED, $version, ['mode' => 'archive_listing']);
        }

        return response()->json($listing);
    }

    /**
     * One file out of an archive.
     *
     * Taking a single file out is taking a copy, so this is gated on `download` even though the
     * document it came from may be previewed by more people than that.
     */
    public function archiveEntry(Request $request, Document $document, DocumentVersion $version, int $index): Response
    {
        $this->authorize('download', $document);

        $version = $this->resolveVersion($document, $version);

        $entry = app(ArchiveBrowser::class)->entry($version, $index);

        if ($entry === null) {
            abort(404, 'That file is not in this archive, or is too large to open from here.');
        }

        $this->documents->record($document, DocumentEvent::DOWNLOADED, $version, [
            'mode' => 'archive_entry',
            // Named, so the trail says which file somebody took rather than that they took a zip.
            'entry' => $entry['name'],
        ]);

        $inline = $request->boolean('inline');

        // The name is rebuilt rather than echoed: a filename with quotes or newlines in it can
        // otherwise break out of the header and set ones we did not intend.
        $safeName = Str::of($entry['name'])
            ->replaceMatches('/[^\w.\- ]+/u', '_')
            ->limit(200, '')
            ->trim()
            ->value() ?: 'file';

        return response($entry['bytes'], 200, [
            'Content-Type' => $inline ? $entry['mime'] : 'application/octet-stream',
            'Content-Length' => (string) strlen($entry['bytes']),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$safeName.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    public function preview(Document $document, ?DocumentVersion $version = null): Response
    {
        // Viewing on screen, not taking a copy — deliberately gated on `view`, not `download`.
        $this->authorize('view', $document);

        $version = $this->resolveVersion($document, $version);

        if (! $this->guard->canPreview($version->mime)) {
            abort(415, 'This file type cannot be previewed. Download it instead.');
        }

        $this->documents->record($document, DocumentEvent::VIEWED, $version, ['mode' => 'preview']);

        /*
         * Stamped with who is looking at it, where the company has asked for that.
         *
         * Read-only stops the download and does nothing about a photograph of the screen, which is
         * how sensitive documents actually leave. The only defence against that is making the copy
         * identify whoever was looking, so the stamp names the reader and the minute.
         *
         * Served plain when the stamp cannot be applied. Refusing instead would mean a document
         * becomes unreadable because of the shape of its PDF, and the person refused would have no
         * idea why — the failure is recorded rather than shown.
         */
        if ($this->shouldWatermarkPreview($document)) {
            $watermarks = app(\App\Modules\Operations\DocumentManagement\Services\WatermarkService::class);

            if ($watermarks->supports((string) $version->mime, (string) $version->extension)) {
                $bytes = $this->documents->contents($version);

                $stamped = $bytes === null ? null : $watermarks->apply(
                    $bytes,
                    (string) $version->mime,
                    (string) $version->extension,
                    $watermarks->textForViewer($this->userName(Auth::id()), $this->companyName()),
                );

                if ($stamped !== null) {
                    return $this->streamBytes($stamped, $version, inline: true);
                }

                Log::warning('Preview watermark could not be applied.', [
                    'document' => $document->uuid,
                    'version' => $version->uuid,
                ]);
            }
        }

        return $this->stream($document, $version, inline: true);
    }

    /**
     * Whether this preview should carry the reader's name.
     *
     * Confidential documents are covered by their own setting, on by default: they are exactly the
     * ones worth stamping, and a company that never opens the settings page should still get that.
     * Everything else is off unless asked for, because stamping is real work on every view and most
     * documents do not need it.
     */
    private function shouldWatermarkPreview(Document $document): bool
    {
        $settings = DocumentSetting::current();

        if ($settings->watermark_previews) {
            return true;
        }

        return (bool) $settings->watermark_confidential_previews
            && ($document->isConfidential() || $document->download_disabled);
    }

    private function companyName(): ?string
    {
        $id = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        return $id ? \Illuminate\Support\Facades\DB::table('companies')->where('id', $id)->value('name') : null;
    }

    /**
     * Files this document in another folder as well.
     *
     * Needs the right to change the document rather than the right to upload into the folder: the
     * document is not moving, and nothing new arrives anywhere. What it needs is somebody entitled
     * to say where this document is found.
     */
    public function shortcut(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        if ($request->boolean('remove')) {
            \App\Modules\Operations\DocumentManagement\Models\DocumentShortcut::query()
                ->where('document_id', $document->id)
                ->where('uuid', $request->input('shortcut'))
                ->delete();

            return back()->with('success', __('document_management.shortcuts.removed'));
        }

        $data = $request->validate(['folder' => ['required', 'string']]);

        $folder = DocumentFolder::where('uuid', $data['folder'])->first();

        if (! $folder) {
            return back()->with('error', __('document_management.access.not_found'));
        }

        // A shortcut in the folder the document already lives in is a duplicate row that renders as
        // the same document listed twice.
        if ((int) $folder->id === (int) $document->folder_id) {
            return back()->with('error', __('document_management.shortcuts.already_there'));
        }

        \App\Modules\Operations\DocumentManagement\Models\DocumentShortcut::firstOrCreate(
            ['document_id' => $document->id, 'folder_id' => $folder->id],
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $request->user()->id],
        );

        return back()->with('success', __('document_management.shortcuts.added', ['folder' => $folder->name]));
    }

    /**
     * Points this document at the one that replaced it, or unpoints it.
     *
     * Needs the right to change the document, because that is what it is: a statement about this
     * document rather than about the newer one.
     */
    public function supersede(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        if ($request->boolean('clear')) {
            $this->documents->unsupersede($document);

            return back()->with('success', __('document_management.superseded.cleared'));
        }

        $data = $request->validate(['replacement' => ['required', 'string']]);

        $replacement = Document::where('uuid', $data['replacement'])->first();

        // Checked rather than assumed: pointing at something the person cannot open would let them
        // confirm it exists, and would send every reader of this document to a refusal.
        if (! $replacement || ! $request->user()->can('view', $replacement)) {
            return back()->with('error', __('document_management.access.not_found'));
        }

        $result = $this->documents->supersede($document, $replacement);

        return $result['ok']
            ? back()->with('success', __('document_management.superseded.done'))
            : back()->with('error', $result['error']);
    }

    /**
     * Claims a document to work on it.
     *
     * Needs the right to change it, because that is what the claim is for: checking out something
     * you could not edit anyway would block colleagues to no purpose.
     */
    public function checkOut(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $result = app(LockService::class)->checkOut(
            $document,
            $request->user(),
            $request->input('note'),
        );

        return $result['ok']
            ? back()->with('success', __('document_management.lock.checked_out'))
            : back()->with('error', $result['error']);
    }

    /**
     * Puts it back, or takes it back.
     *
     * Taking somebody else's lock is a separate ability rather than a flag on this one: a colleague
     * who left the company still holding a document has to be recoverable from, but not by anybody
     * who happens to want the file.
     */
    public function checkIn(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $force = $request->boolean('force');

        if ($force && ! $request->user()->can('breakLock', $document)) {
            return back()->with('error', __('document_management.lock.cannot_break'));
        }

        $result = app(LockService::class)->checkIn($document, $request->user(), $force);

        return $result['ok']
            ? back()->with('success', __('document_management.lock.checked_in'))
            : back()->with('error', $result['error']);
    }

    /**
     * Marks a document read-only, or lifts the marking.
     *
     * A property of the document rather than of any one person: some documents are for reading and
     * not for circulating, and saying so once is better than withholding downloading from people
     * one at a time and hoping the list stays complete.
     */
    public function downloadPolicy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('setDownloadPolicy', $document);

        $restricted = $request->boolean('restricted');

        $document->forceFill(['download_disabled' => $restricted])->save();

        $this->documents->record(
            $document,
            $restricted ? DocumentEvent::DOWNLOAD_RESTRICTED : DocumentEvent::DOWNLOAD_ALLOWED,
        );

        return back()->with('success', __($restricted
            ? 'document_management.downloads.restricted'
            : 'document_management.downloads.allowed'));
    }

    public function download(Document $document, ?DocumentVersion $version = null): Response
    {
        $this->authorize('download', $document);

        $version = $this->resolveVersion($document, $version);

        $this->documents->record($document, DocumentEvent::DOWNLOADED, $version);

        return $this->stream($document, $version, inline: false);
    }

    /**
     * The only place bytes leave this module.
     *
     * `nosniff` on every response, whichever way it goes. Without it a browser is free to ignore
     * the declared type and guess from the content — which is exactly how a file that passed an
     * extension check gets executed as something else.
     */
    /**
     * Stars a document, or takes the star off.
     *
     * A favourite is private to the person who set it, so there is nothing to authorise beyond
     * being able to see the document at all.
     */
    public function toggleFavourite(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        $existing = \App\Modules\Operations\DocumentManagement\Models\DocumentFavourite::query()
            ->where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return back();
        }

        \App\Modules\Operations\DocumentManagement\Models\DocumentFavourite::create([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'created_at' => now(),
        ]);

        return back();
    }

    /**
     * What the assistant panel needs, including the reason it cannot run.
     *
     * @return array{available: bool, reason: ?string, summary: ?array<string, mixed>, history: array<int, array<string, mixed>>}
     */
    private function aiPanel(Document $document): array
    {
        $ai = app(\App\Modules\Operations\DocumentManagement\Services\DocumentAiService::class);
        $available = $ai->availableFor($document);

        $shape = fn ($insight) => $insight === null ? null : [
            'uuid' => $insight->uuid,
            'question' => $insight->question,
            'content' => $insight->content,
            'data' => $insight->data,
            'grounded' => (bool) $insight->grounded,
            'created_at' => $insight->created_at?->toDayDateTimeString(),
            'asked_by' => $this->userName($insight->created_by),
        ];

        // A reading waiting to be accepted or rejected, if there is one for the current version.
        $suggestion = app(\App\Modules\Operations\DocumentManagement\Services\DocumentFieldExtractor::class)
            ->pendingFor($document);

        // The stored summary is shown even when the feature has since been switched off — it was
        // generated when it was allowed, and hiding it would look like data loss.
        $summary = \App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight::query()
            ->where('version_id', $document->current_version_id)
            ->where('kind', \App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight::SUMMARY)
            ->latest('id')
            ->first();

        return [
            'available' => $available['ok'],
            'reason' => $available['reason'],
            'summary' => $shape($summary),
            'history' => $ai->history($document)->map($shape)->all(),
            'fields' => $suggestion === null ? null : [
                'uuid' => $suggestion->uuid,
                'data' => $suggestion->data ?? [],
                'created_at' => $suggestion->created_at?->toDayDateTimeString(),
            ],
            // Named by the server so the page cannot offer a field the extractor will refuse to
            // apply, which would be a checkbox that silently does nothing.
            'applicable_fields' => array_keys(\App\Modules\Operations\DocumentManagement\Services\DocumentFieldExtractor::APPLICABLE),
        ];
    }

    /**
     * Writes the edit into the document's history.
     *
     * Only the fields that actually changed, with their old and new values — a diff is what somebody
     * asking "who changed the expiry date" needs, and a bare "updated" answers nothing.
     *
     * @param array<string, mixed> $before
     */
    private function recordChanges(Document $document, array $before): void
    {
        $watched = [
            'title', 'description', 'notes', 'tags', 'folder_id', 'category_id',
            'document_number', 'document_type', 'department_unit_id', 'owner_id',
            'document_date', 'effective_date', 'expiry_date', 'confidentiality',
            'blocks_when_expired',
        ];

        $changes = [];

        foreach ($watched as $field) {
            $old = $before[$field] ?? null;
            $new = $document->getAttribute($field);

            // Compared as strings: a date cast to Carbon and the same date read back from the
            // database are not identical objects, and would otherwise register as a change on
            // every save.
            if ((string) $old !== (string) $new) {
                $changes[$field] = ['from' => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old,
                    'to' => $new instanceof \DateTimeInterface ? $new->format('Y-m-d') : $new];
            }
        }

        if ($changes === []) {
            return;
        }

        if (isset($changes['title'])) {
            $this->documents->record($document, DocumentEvent::RENAMED, null, $changes['title']);
        }

        if (isset($changes['folder_id'])) {
            $this->documents->record($document, DocumentEvent::MOVED, null, [
                'from' => DocumentFolder::withoutGlobalScopes()->find($changes['folder_id']['from'])?->name,
                'to' => $document->folder?->name,
            ]);
        }

        $other = collect($changes)->except(['title', 'folder_id']);

        if ($other->isNotEmpty()) {
            $this->documents->record($document, DocumentEvent::UPDATED, null, ['changed' => $other->all()]);
        }
    }

    private function stream(Document $document, DocumentVersion $version, bool $inline): Response
    {
        // The headers live in the trait, shared with the public share route. Two copies would
        // eventually differ, and the one that lost nosniff would be the unauthenticated one.
        return $this->streamVersion($this->documents, $version, $inline);
    }

    private function resolveVersion(Document $document, ?DocumentVersion $version): DocumentVersion
    {
        // A version from another document is not this document's to serve, whatever the URL says.
        if ($version !== null) {
            abort_if((int) $version->document_id !== (int) $document->id, 404);

            return $version;
        }

        $current = $document->currentVersion;
        abort_if($current === null, 404, 'This document has no file yet.');

        return $current;
    }

    // ── Shaping ──────────────────────────────────────────────────────────────

    private function row(Document $document): array
    {
        $version = $document->currentVersion;
        $user = Auth::user();

        return [
            'uuid'        => $document->uuid,
            'title'       => $document->title,
            'description' => $document->description,
            'status'      => $document->status,
            'folder'      => $document->folder?->name,
            'folder_uuid' => $document->folder?->uuid,
            'is_confidential'      => $document->isConfidential(),
            'is_legally_sensitive' => (bool) $document->is_legally_sensitive,
            'download_disabled'    => (bool) $document->download_disabled,
            /*
             * Said on the document itself, not only in a listing. Somebody arriving from a link
             * sent last year sees nothing but this page, and it has to tell them the policy they
             * are reading is not the one in force — and where the one in force is.
             */
            'superseded_by'        => $document->supersededBy?->uuid,
            'superseded_by_title'  => $document->supersededBy?->title,
            'superseded_at'        => $document->superseded_at?->toFormattedDateString(),
            // Said explicitly, because the delete button has to explain itself before it is pressed.
            'can_delete'  => $document->isDestructible(),

            /*
             * What this person may do with this document, answered per row.
             *
             * The row menu offered archive and delete and nothing else, so the ordinary things —
             * opening it, downloading it, sending it for approval — were either invisible or
             * reachable only by guessing that the title was a link. Driving the menu from the
             * policy means a reader is shown what a reader can do rather than a list of refusals.
             */
            'can' => [
                'update'   => $user?->can('update', $document) ?? false,
                'download' => $user?->can('download', $document) ?? false,
                'archive'  => $user?->can('archive', $document) ?? false,
                'restore'  => $user?->can('restore', $document) ?? false,
                'share'    => $user?->can('share', $document) ?? false,
                'replace'  => $user?->can('replace', $document) ?? false,
            ],
            'versions'    => (int) $document->version_count,
            'extension'   => $version?->extension,
            'mime'        => $version?->mime,
            'size'        => $version?->humanSize(),
            'previewable' => $this->guard->canPreview($version?->mime),
            'updated_at'  => $document->updated_at?->toDayDateTimeString(),
            'archived_at' => $document->archived_at?->toDayDateTimeString(),
            'archive_reason' => $document->archive_reason,

            // Metadata the listing can show without opening anything. Expiry earns its place here
            // because a certificate that lapsed last week is the thing somebody needs to see from
            // the list rather than discover inside it.
            'document_number' => $document->document_number,
            'document_type' => $document->document_type,
            'category' => $document->category?->name,
            'category_colour' => $document->category?->colour,
            'confidentiality' => $document->confidentiality,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'is_expired' => $document->isExpired(),
            'tags' => $document->tags ?? [],
        ];
    }

    /**
     * The filing structure, flat and ordered by path.
     *
     * Flat rather than nested because `path` already encodes the nesting and `depth` gives the
     * indent — the tree renders from one ordered list, and no part of the UI has to walk children.
     *
     * Archived folders are included and marked. Hiding them would make an archived branch look
     * deleted, and the person who archived it needs to find it again to restore it.
     */
    /**
     * The "you cannot open this" page, where asking is the obvious next step.
     *
     * Returns null when the person may view the document, so the ordinary page runs. Legally
     * sensitive documents are excluded: telling somebody a document exists and is being preserved
     * for a matter is itself a disclosure, and a request to see one is not answered by a share.
     */
    private function accessRefusal(Document $document): ?InertiaResponse
    {
        $user = Auth::user();

        if (! $user || $user->can('view', $document)) {
            return null;
        }

        if ($document->is_legally_sensitive || ! $user->can('document.view')) {
            return null;
        }

        $pending = \App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest::query()
            ->where('document_id', $document->id)
            ->where('requested_by', $user->id)
            ->pending()
            ->first();

        /*
         * Recorded, because "who has been trying to open the disciplinary file" is a question worth
         * being able to answer — and this is the one moment where somebody demonstrably tried.
         *
         * Deduplicated to once an hour per person per document. This is a GET, so a refresh, a
         * back button or a browser prefetch would otherwise write a row each, and a trail full of
         * one person reloading tells nobody anything.
         */
        $recently = DocumentEvent::query()
            ->where('document_id', $document->id)
            ->where('user_id', $user->id)
            ->where('action', DocumentEvent::ACCESS_REFUSED)
            ->where('created_at', '>', now()->subHour())
            ->exists();

        if (! $recently) {
            $this->documents->record($document, DocumentEvent::ACCESS_REFUSED, null, [
                'requested' => $pending !== null,
            ]);
        }

        return Inertia::render('Modules/DocumentManagement/Pages/NoAccess', [
            'document' => [
                'uuid' => $document->uuid,
                // The title and nothing else. Everything that made it confidential stays withheld.
                'title' => $document->title,
                'folder' => $document->folder?->name,
            ],
            'already_requested' => $pending !== null,
        ]);
    }

    /**
     * The name of the slice being shown, or null for the library itself.
     *
     * Read from the route rather than passed around, so a slice added later cannot be given a
     * filter and forgotten in the heading — the route name is the one thing it must already have.
     *
     * @return array{key: ?string, uploadable: bool}
     */
    private function currentView(Request $request): array
    {
        $name = $request->route()?->getName();
        $key = $name ? Str::after($name, 'document-management.') : null;

        // The library, the upload screen and a folder are all just "the documents", so they keep
        // the ordinary heading and the dropzone.
        $plain = ['index', 'upload', 'folders', 'library'];

        /*
         * And only for somebody who may actually upload.
         *
         * `uploadable` answered "does uploading mean anything on this screen", which is not the
         * same question as "may this person upload". A reader was shown a dropzone occupying half
         * the page that refuses every file dropped on it, and nothing else on the screen
         * suggested what they were supposed to do instead.
         */
        $mayUpload = Auth::user()?->can('create', Document::class) ?? false;

        if ($key === null || in_array($key, $plain, true)) {
            return ['key' => null, 'uploadable' => $mayUpload];
        }

        /*
         * Nothing is uploaded into a queue. Offering a dropzone on "Awaiting approval" invites
         * somebody to drop a file and wonder why it did not appear there — it went into the
         * library, which is the only place it could have gone.
         */
        return ['key' => $key, 'uploadable' => false];
    }

    private function folderTree(): array
    {
        $user = Auth::user();
        $closed = app(FolderAccess::class)->closedFolderIds($user);

        return DocumentFolder::query()->orderBy('path')->get()
            // A folder somebody cannot open should not be in their tree at all — an entry that
            // refuses every click is worse than an absence.
            ->reject(fn (DocumentFolder $f) => in_array($f->id, $closed, true))
            ->map(fn (DocumentFolder $f) => [
                'uuid' => $f->uuid,
                'name' => $f->name,
                'path' => $f->path,
                'depth' => $f->depth,
                'is_confidential' => $f->isConfidential(),
                'scope' => $f->scope,
                'department_unit_id' => $f->department_unit_id,
                'archived' => $f->isArchived(),
                'documents' => $f->documents()->where('status', Document::STATUS_ACTIVE)->count(),
                'can' => app(FolderAccess::class)->abilities($user, $f),
            ])->all();
    }

    /** The categories an upload form may offer — active ones, in the order set for them. */
    private function categoryList(): array
    {
        return DocumentCategory::usable()->get()
            ->map(fn (DocumentCategory $c) => [
                'uuid' => $c->uuid,
                'name' => $c->name,
                'colour' => $c->colour,
                'icon' => $c->icon,
            ])->all();
    }

    /** The kinds of record a document can be linked to, for the relation picker. */
    private function relationList(): array
    {
        return collect(DocumentRelations::available())
            ->map(fn (array $definition, string $key) => ['value' => $key, 'label' => $definition['label']])
            ->values()->all();
    }

    private function settingsPayload(): array
    {
        $settings = DocumentSetting::current();

        return [
            'max_file_size_mb'     => $settings->max_file_size_mb,
            'max_files_per_upload' => $settings->max_files_per_upload,
            'allowed_extensions'   => $this->guard->allowedFor($settings),
            'require_description'  => (bool) $settings->require_description,
        ];
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        // There is no users.name column on this platform; the person's name lives on hr_people.
        $person = \Illuminate\Support\Facades\DB::table('hr_people')
            ->where('user_id', $userId)->first(['first_name', 'surname']);

        return $person
            ? trim($person->first_name . ' ' . $person->surname)
            : \App\Core\User\Models\User::where('id', $userId)->value('email');
    }



    /**
     * Puts an earlier version back as the current one.
     *
     * Gated on `replace` rather than a permission of its own: restoring is changing which file the
     * document is, which is the same act as replacing it. A separate permission would be one more
     * thing to grant and would be granted alongside replace every time.
     */
    public function restoreVersion(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        $this->authorize('replace', $document);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $result = $this->documents->restoreVersion($document, $version, $data['reason'] ?? null);

        return $result['version']
            ? back()->with('success', __('document_management.versions.restored', [
                'version' => $result['version']->label(),
            ]))
            : back()->with('error', $result['error']);
    }


    /**
     * The filters behind the listing.
     *
     * A phrase typed into the search box is read for the parts a person can rely on — "expiring
     * within 30 days", a category name, the kind of record — and whatever is left becomes the text
     * search. Explicit filters from the panel win over anything the phrase implied, because
     * somebody who has set a control means it.
     *
     * @return array<string, mixed>
     */
    private function searchFilters(Request $request): array
    {
        $phrase = trim((string) $request->query('q', ''));
        $parsed = $phrase === '' ? [] : app(DocumentSearch::class)->parse($phrase);

        /*
         * The route's own defaults.
         *
         * Read once, here, because every card in the navigation reaches its slice of the library
         * through one — and $request->query() cannot see a route default, so a card that declared
         * one was quietly showing the whole library instead. Archived showed active documents,
         * Confidential showed everything, and each of them looked like it had filtered.
         */
        $defaults = $request->route()?->defaults ?? [];

        $explicit = array_filter([
            'status' => $defaults['status'] ?? $request->query('status', Document::STATUS_ACTIVE),
            'folder' => $request->query('folder'),
            'category' => $request->query('category'),
            'document_type' => $request->query('document_type'),
            'department_unit_id' => $request->query('department_unit_id'),
            'owner_id' => $request->query('owner_id'),
            'uploaded_by' => $request->query('uploaded_by'),
            'confidentiality' => $request->query('confidentiality'),
            'relation' => $request->query('relation'),
            'relation_ids' => array_filter((array) $request->query('relation_ids', [])),
            'tags' => array_filter((array) $request->query('tags', [])),
            'expiry_date_from' => $request->query('expiry_from'),
            'expiry_date_to' => $request->query('expiry_to'),
            'created_at_from' => $request->query('uploaded_from'),
            'created_at_to' => $request->query('uploaded_to'),
            'sort' => $request->query('sort'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        /*
         * Awaiting approval and awaiting signature, which are route defaults rather than something
         * anybody types. Both are "in progress" rather than a single status, since the queue people
         * mean includes the ones part-way through.
         */
        if ($request->route()?->defaults['approval_status'] ?? null) {
            $explicit['approval_status'] = [Document::APPROVAL_SUBMITTED, Document::APPROVAL_UNDER_REVIEW];
        }

        if ($request->route()?->defaults['signature_status'] ?? null) {
            $explicit['signature_status'] = [
                \App\Modules\Operations\DocumentManagement\Services\SignatureService::AWAITING,
                \App\Modules\Operations\DocumentManagement\Services\SignatureService::PARTIAL,
            ];
        }

        /*
         * The two governance slices, which had no filter behind them at all: the Confidential card
         * listed the entire library and the Legal hold card did the same. Both are route defaults
         * for the same reason the expiry ones are — nobody types them.
         */
        if ($defaults['confidential'] ?? false) {
            $explicit['is_confidential'] = true;
        }

        if ($defaults['legally_sensitive'] ?? false) {
            $explicit['legally_sensitive'] = true;
        }

        if ($defaults['unreadable'] ?? false) {
            $explicit['unreadable'] = true;
        }

        /*
         * The personal slices. Each carries the person rather than a flag, because "mine" means
         * nothing without knowing whose — and a filter that silently applied to nobody would show
         * the whole library under a heading saying it was yours.
         */
        $user = $request->user();

        if ($user) {
            foreach (['mine', 'favourites', 'recent'] as $slice) {
                if ($defaults[$slice] ?? false) {
                    $explicit[$slice] = $user->id;
                }
            }

            if ($defaults['shared_with_me'] ?? false) {
                $explicit['shared_with_me'] = $user;
            }
        }

        /*
         * The expiry shortcuts, reached either from the banner and toggle (a query parameter) or
         * from their own hub cards (a route default). $request->boolean does not see a route
         * default, so both sources are read — the cards silently returned the whole library when
         * only the query parameter was checked.
         */
        $defaults = $request->route()?->defaults ?? [];

        if ($request->boolean('expiring') || ($defaults['expiring'] ?? false)) {
            $explicit['expiring_within'] = 90;
            $explicit['sort'] ??= 'expiry';
        }

        if ($request->boolean('expired') || ($defaults['expired'] ?? false)) {
            $explicit['expired'] = true;
        }

        return array_merge($parsed, $explicit);
    }

    /**
     * Finds records to link a document to.
     *
     * Gated on being able to view documents at all, not on anything in the module being searched.
     * That is the honest boundary: this returns names and reference numbers that anybody who can
     * open the library can already see on the documents in it — but it is worth stating, because a
     * search endpoint over eight modules is exactly the kind of thing that quietly becomes a way to
     * enumerate records somebody was not meant to browse. If a module needs its own rule, its own
     * picker is the place for it.
     */
    public function searchRelations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'relation' => ['required', 'string', Rule::in(DocumentRelations::names())],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'results' => DocumentRelations::search($data['relation'], $data['q'] ?? ''),
        ]);
    }


    /**
     * The approval in progress, as the screen needs it.
     *
     * Every step is sent, not just the current one — somebody looking at a document in review wants
     * to know what is still ahead of it, and "waiting on finance" means nothing without knowing
     * that legal comes after.
     *
     * `can_decide` is worked out here rather than in the page, because whether an assignment is
     * this person's depends on delegation, and a browser guessing at that would either hide a
     * button somebody needs or show one that fails.
     */
    private function approvalPayload(Document $document): ?array
    {
        $request = $document->approvalRequest;

        if (! $request) {
            return null;
        }

        $user = Auth::user();

        return [
            'uuid' => $request->uuid,
            'status' => $request->status,
            'workflow' => $request->workflow?->name,
            'submitted_by' => $this->userName($request->submitted_by),
            'submitted_at' => $request->submitted_at?->toDayDateTimeString(),
            'completed_at' => $request->completed_at?->toDayDateTimeString(),
            'outcome_note' => $request->outcome_note,
            'current_position' => $request->current_position,
            'is_open' => $request->isOpen(),
            'steps' => $request->assignments()->with(['assignee', 'delegate'])->get()
                ->map(fn (DocumentApprovalAssignment $a) => [
                    'uuid' => $a->uuid,
                    'position' => $a->position,
                    'name' => $a->step_name,
                    'status' => $a->status,
                    'assigned_to' => $this->userName($a->assigned_to) ?: $a->assigned_role,
                    'delegated_to' => $this->userName($a->delegated_to),
                    'delegation_reason' => $a->delegation_reason,
                    'decided_by' => $this->userName($a->decided_by),
                    'decided_at' => $a->decided_at?->toDayDateTimeString(),
                    'comment' => $a->comment,
                    'due_at' => $a->due_at?->toDateString(),
                    'is_overdue' => $a->isOverdue(),
                    'is_current' => $a->position === $request->current_position,
                    'can_decide' => $request->isOpen()
                        && $a->status === DocumentApprovalAssignment::PENDING
                        && $a->position === $request->current_position
                        && $a->isFor($user),
                ])->values()->all(),
        ];
    }

    /**
     * The metadata fields from a request, ready to fill.
     *
     * `$single` guards the title: naming ten files at once the same thing helps nobody, and the
     * upload form offers it only when one file was chosen.
     *
     * @return array<string, mixed>
     */
    private function metadataFrom(Request $request, bool $single): array
    {
        $fields = [
            'document_number' => $request->input('document_number'),
            'document_type' => $request->input('document_type'),
            'department_unit_id' => $request->input('department_unit_id'),
            'owner_id' => $request->input('owner_id'),
            'document_date' => $request->input('document_date'),
            'effective_date' => $request->input('effective_date'),
            'expiry_date' => $request->input('expiry_date'),
            'confidentiality' => $request->input('confidentiality'),
            'tags' => $request->input('tags'),
            'custom_fields' => $request->input('custom_fields'),
        ];

        if ($request->filled('category')) {
            $fields['category_id'] = DocumentCategory::where('uuid', $request->input('category'))->value('id');
        }

        if ($single && $request->filled('title')) {
            $fields['title'] = $request->input('title');
        }

        // Only what was actually sent. A partial update should not blank the fields it left out.
        return array_filter($fields, fn ($value) => $value !== null);
    }

    /**
     * Points a document at the records it is about.
     *
     * A link naming a record that does not exist is dropped rather than stored: the id comes from a
     * form, and a dangling link renders as a blank row somebody has to investigate.
     *
     * @param  array<int, array{relation:string, id:int}>  $links
     */
    private function applyLinks(Document $document, array $links): void
    {
        foreach ($links as $link) {
            $definition = DocumentRelations::definition($link['relation'] ?? '');

            if (! $definition) {
                continue;
            }

            $record = $definition['model']::find($link['id']);

            if ($record) {
                $document->linkTo($record, $link['relation']);
            }
        }
    }
}
