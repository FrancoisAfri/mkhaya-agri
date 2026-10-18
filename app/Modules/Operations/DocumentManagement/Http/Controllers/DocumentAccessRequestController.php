<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\AccessRequestService;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking to be let in, and the queue of people asking.
 *
 * Requesting needs nothing but being able to use the module: refusing somebody the right to ask
 * would leave them exactly where the bare 403 did. Answering needs document.share, because a grant
 * is what an answer creates.
 */
class DocumentAccessRequestController extends Controller
{
    public function __construct(private readonly AccessRequestService $requests)
    {
    }

    /**
     * The queue, and the form for joining it.
     *
     * Opening the screen needs no more than being able to use the module — asking for access is the
     * point of it, and requiring the right to *answer* requests before you may *make* one would
     * shut out exactly the people it exists for. The queue itself is only rendered for whoever can
     * answer it.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $mayAnswer = $request->user()?->can('share', Document::class) ?? false;

        $user = $request->user();

        return Inertia::render('Modules/DocumentManagement/Pages/AccessRequests', [
            /*
             * What somebody can ask for, in bulk.
             *
             * Folders are offered whole, because their names are already on the tree whether or not
             * the person can open them — that is what makes "I need Finance and Legal" a thing
             * somebody can express here rather than in a corridor.
             *
             * Documents are offered only where they are already visible. Listing the ones they
             * cannot open would disclose the titles, and a title is often the sensitive part —
             * "Disciplinary hearing — J. Dlamini" needs no file attached to do damage. Somebody sent
             * a link to a document they cannot see still asks from the document's own page.
             */
            'askable' => [
                'folders' => DocumentFolder::query()
                    ->whereNull('archived_at')
                    ->orderBy('path')
                    ->get()
                    ->map(fn (DocumentFolder $f) => [
                        'uuid' => $f->uuid,
                        'name' => $f->name,
                        'depth' => (int) $f->depth,
                        'open' => app(FolderAccess::class)->can($user, $f, 'view'),
                    ])
                    ->values(),

                'documents' => Document::query()
                    ->visibleTo($user)
                    ->whereNull('archived_at')
                    ->orderByDesc('updated_at')
                    ->limit(300)
                    ->get(['uuid', 'title'])
                    ->map(fn (Document $d) => ['uuid' => $d->uuid, 'title' => $d->title])
                    ->values(),
            ],

            'may_answer' => $mayAnswer,

            'requests' => $mayAnswer ? $this->requests->pending()->limit(100)->get()->map(fn (DocumentAccessRequest $r) => [
                'uuid' => $r->uuid,
                'subject' => $r->document?->title ?? $r->folder?->name,
                'subject_type' => $r->document_id ? 'document' : 'folder',
                'kind' => $r->kind ?? DocumentAccessRequest::KIND_ACCESS,
                'reason' => $r->reason,
                'asked_at' => $r->created_at?->diffForHumans(),
                'by' => $this->name($r->requestedBy),
            ])->values() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Anybody who can use the repository may ask. The point of this feature is that being
        // refused is not the end of the conversation.
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'document' => ['nullable', 'string'],
            'folder' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * Resolved without the visibility scope on purpose: the whole point is that the person
         * cannot see this yet. The uuid is the only thing they have, and they only have it because
         * somebody sent it to them.
         */
        $subject = filled($data['document'] ?? null)
            ? Document::withoutGlobalScopes()->where('uuid', $data['document'])->first()
            : DocumentFolder::withoutGlobalScopes()->where('uuid', $data['folder'] ?? '')->first();

        if (! $subject) {
            return back()->with('error', __('document_management.access.not_found'));
        }

        // A document under legal hold is not opened by asking, and saying so would confirm it is
        // being preserved — which is itself the disclosure.
        if ($subject instanceof Document && $subject->is_legally_sensitive) {
            return back()->with('error', __('document_management.access.not_found'));
        }

        $this->requests->request($request->user(), $subject, $data['reason'] ?? null);

        return back()->with('success', __('document_management.access.requested'));
    }

    /**
     * Asking for several things at once.
     *
     * Separate from store() because store() answers the other situation: somebody holding a link to
     * one document they cannot open, who has nothing to pick from. This is the person who knows
     * what they need — the Finance folder, the Legal folder and two contracts — and should not have
     * to send four requests to say so.
     */
    public function storeMany(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'folders' => ['nullable', 'array'],
            'folders.*' => ['string'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $subjects = [];

        foreach (DocumentFolder::whereIn('uuid', $data['folders'] ?? [])->get() as $folder) {
            $subjects[] = $folder;
        }

        /*
         * Documents are resolved through the ordinary scope, unlike store(). Somebody picking from
         * a list can only have picked what the list offered, and the list only offers what they can
         * already see — a uuid posted from anywhere else is not a pick, it is a probe.
         */
        foreach (Document::query()->visibleTo($request->user())->whereIn('uuid', $data['documents'] ?? [])->get() as $document) {
            if ($document->is_legally_sensitive) {
                continue;
            }

            $subjects[] = $document;
        }

        if ($subjects === []) {
            return back()->with('error', __('document_management.access.nothing_chosen'));
        }

        $created = $this->requests->requestMany($request->user(), $subjects, $data['reason'] ?? null);

        return back()->with('success', __('document_management.access.requested_many', ['count' => count($created)]));
    }

    /**
     * Asking for a copy of something they can already read.
     *
     * Separate from store() because it starts from the opposite position: the person is looking at
     * the document, so it is resolved through the normal scope and the policy decides. Somebody who
     * cannot open it at all is asking the other question, and gets the other route.
     */
    public function requestDownload(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('requestDownload', $document);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->requests->request(
            $request->user(),
            $document,
            $data['reason'] ?? null,
            DocumentAccessRequest::KIND_DOWNLOAD,
        );

        return back()->with('success', __('document_management.access.download_requested'));
    }

    public function grant(Request $request, DocumentAccessRequest $accessRequest): RedirectResponse
    {
        $this->authorize('share', Document::class);

        $count = $this->requests->grant($accessRequest, $request->input('note'));

        return back()->with('success', __('document_management.access.granted', ['count' => $count]));
    }

    public function decline(Request $request, DocumentAccessRequest $accessRequest): RedirectResponse
    {
        $this->authorize('share', Document::class);

        $this->requests->decline($accessRequest, $request->input('note'));

        return back()->with('success', __('document_management.access.declined'));
    }

    private function name(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
