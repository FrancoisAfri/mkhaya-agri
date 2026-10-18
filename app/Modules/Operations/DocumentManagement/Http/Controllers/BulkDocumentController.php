<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Doing one thing to many documents.
 *
 * Every document is checked individually against the same policy the single-document action uses.
 * A bulk operation that authorised once and then applied to everything is how somebody moves forty
 * documents and quietly takes three confidential ones with them.
 *
 * What could not be done is counted and said, rather than failing the whole batch: refusing to
 * archive thirty-eight documents because two of them are held helps nobody, and silently archiving
 * thirty-eight while reporting forty is worse.
 */
class BulkDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public function handle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['archive', 'restore', 'delete', 'move', 'tag', 'owner'])],
            'documents' => ['required', 'array', 'min:1', 'max:200'],
            'documents.*' => ['string'],
            'folder' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'owner_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Resolved through the visibility scope: a uuid somebody should not know about answers as
        // though it does not exist, exactly as it does everywhere else.
        $documents = Document::query()
            ->visibleTo($request->user())
            ->whereIn('uuid', $data['documents'])
            ->get();

        $done = 0;
        $refused = 0;

        foreach ($documents as $document) {
            if (! $this->apply($request, $data, $document)) {
                $refused++;

                continue;
            }

            $done++;
        }

        // Documents that resolved to nothing at all — a stale selection, or a uuid for something
        // this person cannot see. Counted with the refusals so the total always adds up.
        $refused += count($data['documents']) - $documents->count();

        return back()->with('success', __('document_management.bulk.done', [
            'done' => $done,
            'refused' => $refused,
        ]));
    }

    /** @param array<string, mixed> $data */
    private function apply(Request $request, array $data, Document $document): bool
    {
        $user = $request->user();

        return match ($data['action']) {
            'archive' => $user->can('archive', $document)
                && ($this->documents->archive($document, $data['reason'] ?? null) || true),

            'restore' => $user->can('restore', $document)
                && ($this->documents->restore($document) || true),

            'delete' => $user->can('delete', $document)
                && $this->documents->delete($document, $data['reason'] ?? null)['ok'],

            'move' => $user->can('update', $document) && $this->move($document, $data['folder'] ?? null),

            'tag' => $user->can('update', $document) && $this->tag($document, $data['tags'] ?? []),

            'owner' => $user->can('update', $document) && $this->assignOwner($document, $data['owner_id'] ?? null),

            default => false,
        };
    }

    private function move(Document $document, ?string $folderUuid): bool
    {
        $folderId = $folderUuid
            ? DocumentFolder::where('uuid', $folderUuid)->value('id')
            : null;

        if ($folderUuid && ! $folderId) {
            return false;
        }

        $from = $document->folder?->name;

        $document->forceFill(['folder_id' => $folderId, 'updated_by' => Auth::id()])->save();

        $this->documents->record($document, DocumentEvent::MOVED, null, [
            'from' => $from,
            'to' => $document->fresh()->folder?->name,
            'bulk' => true,
        ]);

        return true;
    }

    /**
     * Adds tags rather than replacing them.
     *
     * A bulk tag that replaced would wipe whatever each document already carried, which is a
     * destructive operation wearing the name of an additive one.
     *
     * @param list<string> $tags
     */
    private function tag(Document $document, array $tags): bool
    {
        if ($tags === []) {
            return false;
        }

        $merged = collect($document->tags ?? [])
            ->merge($tags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $before = $document->tags;

        $document->forceFill(['tags' => $merged, 'updated_by' => Auth::id()])->save();

        $this->documents->record($document, DocumentEvent::UPDATED, null, [
            'changed' => ['tags' => ['from' => $before, 'to' => $merged]],
            'bulk' => true,
        ]);

        return true;
    }

    private function assignOwner(Document $document, ?int $ownerId): bool
    {
        if (! $ownerId) {
            return false;
        }

        $before = $document->owner_id;

        $document->forceFill(['owner_id' => $ownerId, 'updated_by' => Auth::id()])->save();

        $this->documents->record($document, DocumentEvent::UPDATED, null, [
            'changed' => ['owner_id' => ['from' => $before, 'to' => $ownerId]],
            'bulk' => true,
        ]);

        return true;
    }
}
