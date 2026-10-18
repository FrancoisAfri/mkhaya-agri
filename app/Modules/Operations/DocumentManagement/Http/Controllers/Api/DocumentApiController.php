<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Concerns\ServesDocumentBytes;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\ApprovalService;
use App\Modules\Operations\DocumentManagement\Services\DocumentSearch;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The repository as an API, so a module does not have to build its own document storage.
 *
 * Everything here goes through the same services, policies and visibility scope as the screens. It
 * is a second doorway into one building, not a second building: an endpoint that read documents
 * directly would be the place where the confidentiality rules were eventually forgotten.
 *
 * Authenticated with Sanctum and gated on module entitlement like every other route in the module,
 * because a company that has not bought Document Management should not be able to reach it through
 * an API key either.
 */
class DocumentApiController extends Controller
{
    use ServesDocumentBytes;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentSearch $search,
    ) {
    }

    /** Search and list. The same filters the library screen uses. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $documents = $this->search
            ->query($request->user(), $request->only([
                'q', 'status', 'category', 'document_type', 'tags',
                'expiring_within', 'expired', 'relation', 'relation_ids', 'sort',
            ]))
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($documents->items())->map(fn (Document $d) => $this->shape($d))->all(),
            'meta' => [
                'total' => $documents->total(),
                'per_page' => $documents->perPage(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        // Recorded here as it is on the screen. An API read is still somebody reading the document,
        // and a trail that only knows about browsers is a trail with a hole in it.
        $this->documents->record($document, DocumentEvent::VIEWED, null, ['via' => 'api']);

        return response()->json(['data' => $this->shape($document, detailed: true)]);
    }

    /** Uploads a new document, optionally filed against a record from another module. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:2000'],
            'folder' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
            'document_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            // Filing it against a record in one call, because a document uploaded through an API
            // and then never linked is one nobody will find again.
            'relation' => ['nullable', 'string'],
            'relation_key' => ['nullable', 'string'],
        ]);

        $result = $this->documents->store($request->file('file'), [
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'document_date' => $data['document_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'folder_id' => isset($data['folder'])
                ? DocumentFolder::where('uuid', $data['folder'])->value('id')
                : null,
        ]);

        if ($result['document'] === null) {
            return response()->json(['message' => $result['error']], 422);
        }

        if (filled($data['relation'] ?? null) && filled($data['relation_key'] ?? null)) {
            $this->link($result['document'], $data['relation'], $data['relation_key']);
        }

        return response()->json(['data' => $this->shape($result['document'], detailed: true)], 201);
    }

    /** A new version of an existing document. */
    public function storeVersion(Request $request, Document $document): JsonResponse
    {
        $this->authorize('replace', $document);

        $request->validate([
            'file' => ['required', 'file'],
            'change_note' => ['nullable', 'string', 'max:500'],
            'major' => ['nullable', 'boolean'],
        ]);

        $result = $this->documents->replace(
            $document,
            $request->file('file'),
            $request->input('change_note'),
            $request->boolean('major'),
        );

        return $result['version'] === null
            ? response()->json(['message' => $result['error']], 422)
            : response()->json(['data' => $this->shape($document->fresh(), detailed: true)]);
    }

    /** The bytes. Streams through the same helper as the browser download, headers and all. */
    public function download(Request $request, Document $document): HttpResponse
    {
        $this->authorize('download', $document);

        $version = $document->currentVersion;

        if (! $version) {
            throw new NotFoundHttpException();
        }

        $this->documents->record($document, DocumentEvent::DOWNLOADED, $version, ['via' => 'api']);

        return $this->streamVersion($this->documents, $version, inline: false);
    }

    /** Everything that has happened to this document. */
    public function history(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->events()->limit(200)->get()->map(fn (DocumentEvent $event) => [
                'action' => $event->action,
                'at' => $event->created_at?->toIso8601String(),
                'user_id' => $event->user_id,
                'detail' => $event->detail,
            ])->all(),
        ]);
    }

    /** Sets or clears the expiry date — the field other modules most often need to write. */
    public function setExpiry(Request $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'expiry_date' => ['nullable', 'date'],
            'blocks_when_expired' => ['nullable', 'boolean'],
        ]);

        $document->forceFill([
            'expiry_date' => $data['expiry_date'] ?? null,
            // Cleared alongside the date: an expired marker left behind on a renewed document keeps
            // blocking work that should have been unblocked.
            'expired_at' => null,
        ]);

        if ($request->has('blocks_when_expired')) {
            $document->blocks_when_expired = $request->boolean('blocks_when_expired');
        }

        $document->save();

        return response()->json(['data' => $this->shape($document, detailed: true)]);
    }

    public function archive(Request $request, Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $this->documents->archive($document, $request->input('reason'));

        return response()->json(['data' => $this->shape($document->fresh(), detailed: true)]);
    }

    /** Sends the document into an approval workflow. */
    public function requestApproval(Request $request, Document $document, ApprovalService $approvals): JsonResponse
    {
        $this->authorize('update', $document);

        $result = $approvals->submit($document, null, $request->input('note'));

        return ($result['error'] ?? null)
            ? response()->json(['message' => $result['error']], 422)
            : response()->json(['data' => $this->shape($document->fresh(), detailed: true)]);
    }

    /** Folders, so a module can file into a structure it created. */
    public function folders(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        return response()->json([
            'data' => DocumentFolder::query()
                ->whereNull('archived_at')
                ->orderBy('path')
                ->get()
                ->map(fn (DocumentFolder $folder) => [
                    'uuid' => $folder->uuid,
                    'name' => $folder->name,
                    'path' => $folder->path,
                    'confidential' => (bool) $folder->is_confidential,
                ])->all(),
        ]);
    }

    public function storeFolder(Request $request, FolderService $folders): JsonResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent' => ['nullable', 'string'],
            'is_confidential' => ['nullable', 'boolean'],
        ]);

        $folder = $folders->create([
            'name' => $data['name'],
            'parent_id' => $data['parent'] ?? null,
            'is_confidential' => $request->boolean('is_confidential'),
        ]);

        return response()->json([
            'data' => ['uuid' => $folder->uuid, 'name' => $folder->name, 'path' => $folder->path],
        ], 201);
    }

    /**
     * Files a document against a record from another module.
     *
     * The relation list is the allow-list, exactly as it is for the web route: a request cannot
     * nominate an arbitrary class to instantiate.
     */
    private function link(Document $document, string $relation, string $key): void
    {
        $definition = DocumentRelations::all()[$relation] ?? null;

        if ($definition === null || ! isset($definition['model'])) {
            throw new NotFoundHttpException();
        }

        $record = $definition['model']::query()->whereKey($key)->first();

        if (! $record || ! method_exists($record, 'attachDocument')) {
            throw new NotFoundHttpException();
        }

        $record->attachDocument($document, $relation);
    }

    /** @return array<string, mixed> */
    private function shape(Document $document, bool $detailed = false): array
    {
        $base = [
            'uuid' => $document->uuid,
            'title' => $document->title,
            'document_number' => $document->document_number,
            'status' => $document->status,
            'category' => $document->category?->name,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'is_expired' => $document->isExpired(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'description' => $document->description,
            'folder' => $document->folder?->name,
            'confidentiality' => $document->confidentiality,
            'approval_status' => $document->approval_status,
            'signature_status' => $document->signature_status,
            'version' => $document->currentVersion?->label(),
            'versions' => (int) $document->version_count,
            'size' => $document->currentVersion?->size,
            'mime' => $document->currentVersion?->mime,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
