<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentLegalHold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Placing and lifting legal holds.
 *
 * A hold suspends every route by which a document could stop existing: deletion, permanent
 * destruction, and retention disposal. It deliberately does not suspend ordinary work — a held
 * document can still be read, downloaded, superseded by a new version and linked to records,
 * because a hold is an instruction to preserve, not to freeze the business.
 *
 * The document's is_legally_sensitive flag is kept in step with the holds rather than being set
 * directly. Two places deciding the same thing is how a document ends up flagged with no hold
 * behind it, or held with nothing to show for it in a listing.
 */
class LegalHoldService
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Places a hold, or returns the one already covering this document under the same matter.
     *
     * Re-placing an identical hold is not an error worth showing anybody: two people asked to
     * preserve the same document for the same case have not made a mistake, and a second row would
     * have to be released twice.
     */
    public function place(Document $document, string $reference, ?string $reason = null): DocumentLegalHold
    {
        $reference = trim($reference);

        return DB::transaction(function () use ($document, $reference, $reason) {
            $existing = DocumentLegalHold::query()
                ->where('document_id', $document->id)
                ->where('reference', $reference)
                ->active()
                ->first();

            if ($existing) {
                return $existing;
            }

            $hold = DocumentLegalHold::create([
                'uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'reference' => $reference,
                'reason' => $reason,
                'placed_by' => Auth::id(),
                'placed_at' => Carbon::now(),
            ]);

            $this->syncFlag($document);

            $this->documents->record($document, DocumentEvent::HOLD_PLACED, null, [
                'reference' => $reference,
                'reason' => $reason,
            ]);

            return $hold;
        });
    }

    /**
     * Lifts one hold.
     *
     * A reason is required by the caller rather than here, but the release is recorded either way:
     * the absence of a stated reason is itself something a later reader should be able to see,
     * rather than something this silently invents.
     *
     * Lifting one hold does not unhold the document if another matter still covers it. That is the
     * common case in practice — two investigations touching the same contract — and getting it
     * wrong destroys evidence while the second matter is still live.
     */
    public function release(DocumentLegalHold $hold, ?string $reason = null): DocumentLegalHold
    {
        if (! $hold->isActive()) {
            return $hold;
        }

        return DB::transaction(function () use ($hold, $reason) {
            $hold->forceFill([
                'released_by' => Auth::id(),
                'released_at' => Carbon::now(),
                'release_reason' => $reason,
            ])->save();

            $document = $hold->document()->withoutGlobalScopes()->first();

            if ($document) {
                $this->syncFlag($document);

                $this->documents->record($document, DocumentEvent::HOLD_RELEASED, null, [
                    'reference' => $hold->reference,
                    'reason' => $reason,
                    'held_days' => $hold->placed_at?->diffInDays($hold->released_at),
                ]);
            }

            return $hold;
        });
    }

    /**
     * Every hold placed under one matter, lifted in one action.
     *
     * A matter ends all at once. Releasing its documents one at a time is how three of them stay
     * held for another two years because somebody's list was out of date.
     *
     * @return int the number of holds lifted
     */
    public function releaseMatter(string $reference, ?string $reason = null): int
    {
        $holds = DocumentLegalHold::query()->where('reference', trim($reference))->active()->get();

        foreach ($holds as $hold) {
            $this->release($hold, $reason);
        }

        return $holds->count();
    }

    /** @return Collection<int, DocumentLegalHold> */
    public function activeFor(Document $document): Collection
    {
        return DocumentLegalHold::query()
            ->where('document_id', $document->id)
            ->active()
            ->orderBy('placed_at')
            ->get();
    }

    /** The whole history for one document, lifted holds included. */
    public function historyFor(Document $document): Collection
    {
        return DocumentLegalHold::query()
            ->with(['placedBy.person', 'releasedBy.person'])
            ->where('document_id', $document->id)
            ->orderByDesc('placed_at')
            ->get();
    }

    /**
     * The register: live holds grouped by the matter they were placed under.
     *
     * Grouped rather than listed flat because the question people bring to it is "what are we
     * holding for case X", and a flat list of documents makes that a counting exercise.
     *
     * @return array<int, array{reference: string, documents: int, oldest: ?Carbon}>
     */
    public function register(): array
    {
        return DocumentLegalHold::query()
            ->active()
            ->selectRaw('reference, count(*) as documents, min(placed_at) as oldest')
            ->groupBy('reference')
            ->orderBy('reference')
            ->get()
            ->map(fn ($row) => [
                'reference' => $row->reference,
                'documents' => (int) $row->documents,
                'oldest' => $row->oldest ? Carbon::parse($row->oldest) : null,
            ])
            ->all();
    }

    /**
     * Brings the document's flag back in line with its holds.
     *
     * forceFill because is_legally_sensitive is guarded on the model — it is not something a
     * request should ever set directly, and after this it is not something a person sets at all.
     */
    private function syncFlag(Document $document): void
    {
        $held = DocumentLegalHold::query()
            ->where('document_id', $document->id)
            ->active()
            ->exists();

        if ((bool) $document->is_legally_sensitive === $held) {
            return;
        }

        $document->forceFill(['is_legally_sensitive' => $held])->save();
    }
}
