<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Deleted documents, and the window in which they can come back.
 *
 * A deletion nobody can undo is the one mistake in a repository that cannot be talked about
 * afterwards, so deleting puts a document here rather than destroying it. What matters as much as
 * the restore is the answer to "who deleted this, and when" — the two questions asked every time
 * somebody finds a document missing.
 *
 * Nothing here destroys on a timer unless a company has switched that on deliberately. The default
 * is that the bin fills up and somebody empties it, because "the system deleted it after thirty
 * days" is the same unanswerable sentence as "the cron deleted it".
 */
class RecycleBin
{
    public function __construct(private readonly DocumentService $documents) {}

    /** How long a company keeps deleted documents before they may be purged. */
    public function retentionDays(): int
    {
        return max(1, (int) (DocumentSetting::current()->recycle_bin_days ?? 30));
    }

    /**
     * What is in the bin, newest deletion first.
     *
     * Scoped through the same visibility rule as the library. A deleted confidential document is
     * still a confidential document, and a bin that showed everything would be the easiest way in
     * the system to read what somebody was refused.
     *
     * @return Builder<Document>
     */
    public function contents(?User $user): Builder
    {
        return Document::onlyTrashed()
            ->visibleTo($user)
            ->with(['category', 'folder'])
            ->orderByDesc('deleted_at');
    }

    /** When this document will become eligible for permanent removal. */
    public function purgeableFrom(Document $document): ?Carbon
    {
        return $document->deleted_at?->copy()->addDays($this->retentionDays());
    }

    /**
     * Puts a document back where it was.
     *
     * Refused where the folder it came from has since been deleted: restoring into a folder that no
     * longer exists produces a document nobody can navigate to, which looks exactly like the
     * restore having failed.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function restore(Document $document): array
    {
        if ($document->folder_id !== null) {
            $folderExists = \App\Modules\Operations\DocumentManagement\Models\DocumentFolder::query()
                ->whereKey($document->folder_id)
                ->exists();

            if (! $folderExists) {
                return ['ok' => false, 'error' => __('document_management.recycle.folder_gone')];
            }
        }

        $document->restore();
        $document->forceFill(['deleted_by' => null, 'delete_reason' => null])->save();

        $this->documents->record($document, DocumentEvent::RESTORED, null, ['from' => 'recycle_bin']);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Everything eligible for permanent removal, for the sweep and for "empty the bin".
     *
     * A document under legal hold is never eligible, whatever its age. That is the whole point of a
     * hold, and the bin is exactly where somebody would look to get around one.
     *
     * @return Builder<Document>
     */
    public function purgeable(): Builder
    {
        return Document::onlyTrashed()
            ->where('deleted_at', '<=', Carbon::now()->subDays($this->retentionDays()))
            ->where(fn ($q) => $q->where('is_legally_sensitive', false)->orWhereNull('is_legally_sensitive'));
    }

    /**
     * Empties the bin of everything past its window.
     *
     * Returns what it did rather than logging it, so the command can print a line and a test can
     * assert on something other than absence.
     *
     * @return array{purged: int, held: int}
     */
    public function empty(bool $includeRecent = false): array
    {
        $query = $includeRecent
            ? Document::onlyTrashed()->where(fn ($q) => $q->where('is_legally_sensitive', false)->orWhereNull('is_legally_sensitive'))
            : $this->purgeable();

        $held = Document::onlyTrashed()->where('is_legally_sensitive', true)->count();
        $purged = 0;

        $query->chunkById(100, function ($documents) use (&$purged) {
            foreach ($documents as $document) {
                if ($this->documents->purge($document)['ok']) {
                    $purged++;
                }
            }
        }, 'id');

        return ['purged' => $purged, 'held' => $held];
    }

    /** @return array{in_bin: int, purgeable: int, held: int, days: int} */
    public function summary(?User $user): array
    {
        return [
            'in_bin' => (clone $this->contents($user))->count(),
            'purgeable' => $this->purgeable()->count(),
            'held' => Document::onlyTrashed()->where('is_legally_sensitive', true)->count(),
            'days' => $this->retentionDays(),
        ];
    }
}
