<?php

namespace App\Modules\Operations\DocumentManagement\Concerns;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentLink;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a record from any module carry documents.
 *
 * This is the half of the repository that faces outwards. A contract, an employee, a vehicle — each
 * can say `$record->documents` and get what the library holds about it, and `attachDocument()` to
 * put something there, without knowing how documents are stored, versioned or permissioned.
 *
 * Adding it to a model is the whole integration. The relation is worked out from the class, so a
 * module does not restate what it is.
 *
 * The methods are named `libraryDocuments` rather than `documents` on purpose. Three of the models
 * this is meant for already have a `documents()` relation of their own — a supplier's own uploads,
 * a tender's own pack — and a class method always beats a trait's. The collision does not raise
 * anything: the model's relation answers instead, returns its own unrelated rows, and the linked
 * documents simply never appear. It cost an afternoon to find once.
 */
trait HasDocuments
{
    public function documentLinks(): MorphMany
    {
        return $this->morphMany(DocumentLink::class, 'linkable');
    }

    /**
     * The documents filed against this record.
     *
     * Ordered newest first, which is what someone opening a record wants — the latest certificate,
     * the most recent signed copy.
     */
    public function libraryDocuments()
    {
        /*
         * Built from DocumentLink directly rather than from the relation above.
         *
         * Handing a relation object to whereIn as a subquery looks equivalent and is not — the morph
         * constraints that make it *this* record's links are applied by the relation, not by the
         * query it wraps, so the subquery matches every link in the table and the outer condition
         * then matches none of them. It returns an empty set rather than an error, which is how it
         * would have shipped.
         */
        return Document::query()
            ->whereIn('id', DocumentLink::query()
                ->where('linkable_type', static::class)
                ->where('linkable_id', $this->getKey())
                ->select('document_id'))
            ->latest('created_at');
    }

    /**
     * Files a document against this record.
     *
     * Idempotent: linking the same document twice is a click, not an error, and the unique key
     * makes the second one a no-op rather than a duplicate row.
     */
    public function attachDocument(Document $document, ?string $relation = null): DocumentLink
    {
        $relation ??= DocumentRelations::forModel($this) ?? 'related';

        return DocumentLink::firstOrCreate(
            [
                'document_id' => $document->id,
                'relation' => $relation,
                'linkable_type' => static::class,
                'linkable_id' => $this->getKey(),
            ],
            [
                // Captured now, so the link still reads properly after this record is renamed or
                // removed.
                'label' => DocumentRelations::labelFor($relation, $this),
                'created_by' => Auth::id(),
            ],
        );
    }

    /** Removes the link, never the document. The document exists in its own right. */
    public function detachDocument(Document $document, ?string $relation = null): void
    {
        $this->documentLinks()
            ->where('document_id', $document->id)
            ->when($relation, fn ($q) => $q->where('relation', $relation))
            ->delete();
    }

    /** Whether anything has been filed here — for a badge, without loading the documents. */
    public function hasLibraryDocuments(): bool
    {
        return $this->documentLinks()->exists();
    }

    /**
     * Whether an expired document is standing in the way of this record.
     *
     * The requirement's example lives here: a supplier whose insurance certificate has lapsed
     * cannot take new bookings until it is renewed. Only documents somebody deliberately marked as
     * blocking are considered, so an old quote sitting in a folder cannot halt anything.
     *
     *     if ($supplier->isBlockedByExpiredDocuments()) {
     *         return back()->with('error', $supplier->documentBlockReason());
     *     }
     */
    public function isBlockedByExpiredDocuments(): bool
    {
        return app(\App\Modules\Operations\DocumentManagement\Services\DocumentCompliance::class)
            ->isBlocked($this);
    }

    /** The sentence to show when it is — naming the documents, not just the fact. */
    public function documentBlockReason(): ?string
    {
        return app(\App\Modules\Operations\DocumentManagement\Services\DocumentCompliance::class)
            ->reason($this);
    }
}
