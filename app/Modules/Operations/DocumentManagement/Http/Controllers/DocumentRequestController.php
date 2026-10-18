<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentRequest;
use App\Modules\Operations\DocumentManagement\Services\DocumentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking people for documents, and answering.
 *
 * One screen showing both directions, because they are the same person's two questions: what do I
 * still owe, and who still owes me. Splitting them across two pages would mean the first is checked
 * and the second is not.
 */
class DocumentRequestController extends Controller
{
    use \App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;

    public function __construct(private readonly DocumentRequestService $requests) {}

    public function index(Request $request): Response
    {
        // Being able to use the module is enough to see what you owe. Asking needs more, and that
        // is checked where the asking happens.
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        return Inertia::render('Modules/DocumentManagement/Pages/DocumentRequests', [
            'owed' => $this->requests->owedBy($user)->get()->map(fn (DocumentRequest $r) => [
                'uuid' => $r->uuid,
                'title' => $r->title,
                'description' => $r->description,
                'by' => $this->name($r->requestedBy),
                'due_at' => $r->due_at?->toFormattedDateString(),
                'overdue' => $r->isOverdue(),
                'folder' => $r->folder?->name,
                'reminders' => (int) $r->reminders_sent,
            ])->values(),

            'asked' => $this->requests->askedBy($user)->limit(200)->get()->map(fn (DocumentRequest $r) => [
                'uuid' => $r->uuid,
                'title' => $r->title,
                'from' => $this->name($r->requestedFrom),
                'status' => $r->status,
                'due_at' => $r->due_at?->toFormattedDateString(),
                'overdue' => $r->isOverdue(),
                'reminders' => (int) $r->reminders_sent,
                'document' => $r->document?->uuid,
                'fulfilled_at' => $r->fulfilled_at?->toFormattedDateString(),
            ])->values(),

            'can_ask' => $request->user()?->can('create', Document::class) ?? false,

            'people' => $this->tenantPeople($request),

            'folders' => DocumentFolder::query()->whereNull('archived_at')->orderBy('path')
                ->get()->map(fn (DocumentFolder $f) => ['uuid' => $f->uuid, 'name' => $f->name, 'depth' => (int) $f->depth]),

            'categories' => DocumentCategory::query()->orderBy('name')
                ->get()->map(fn (DocumentCategory $c) => ['uuid' => $c->uuid, 'name' => $c->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Asking for a document is asking for one to be filed, so it needs the right to file one.
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'people' => ['required', 'array', 'min:1'],
            'people.*' => ['integer'],
            'folder' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
        ]);

        $created = $this->requests->ask($data['title'], $data['people'], [
            'description' => $data['description'] ?? null,
            'folder_id' => filled($data['folder'] ?? null)
                ? DocumentFolder::where('uuid', $data['folder'])->value('id')
                : null,
            'category_id' => filled($data['category'] ?? null)
                ? DocumentCategory::where('uuid', $data['category'])->value('id')
                : null,
            'due_at' => $data['due_at'] ?? null,
        ]);

        return back()->with('success', __('document_management.requests.asked', ['count' => count($created)]));
    }

    /**
     * Answering with a file.
     *
     * Only the person who was asked. Somebody else uploading on their behalf would close a request
     * the person still believes is open, and the queue would be quietly wrong.
     */
    public function fulfil(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless((int) $documentRequest->requested_from === (int) $request->user()->id, 403);

        $request->validate(['file' => ['required', 'file']]);

        $result = $this->requests->fulfil($documentRequest, $request->file('file'));

        return $result['document']
            ? back()->with('success', __('document_management.requests.fulfilled'))
            : back()->with('error', $result['error']);
    }

    /** Answering with something already filed. */
    public function fulfilWith(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless((int) $documentRequest->requested_from === (int) $request->user()->id, 403);

        $data = $request->validate(['document' => ['required', 'string']]);

        $document = Document::where('uuid', $data['document'])->first();

        if (! $document || ! $request->user()->can('view', $document)) {
            return back()->with('error', __('document_management.access.not_found'));
        }

        $this->requests->fulfilWith($documentRequest, $document);

        return back()->with('success', __('document_management.requests.fulfilled'));
    }

    /** Asking again. Only the person waiting on it. */
    public function remind(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless((int) $documentRequest->requested_by === (int) $request->user()->id, 403);

        $this->requests->remind([$documentRequest]);

        return back()->with('success', __('document_management.requests.reminded'));
    }

    public function cancel(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless((int) $documentRequest->requested_by === (int) $request->user()->id, 403);

        $this->requests->cancel($documentRequest, $request->input('reason'));

        return back()->with('success', __('document_management.requests.cancelled'));
    }

    private function name(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
