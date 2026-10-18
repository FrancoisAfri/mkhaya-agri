<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentLink;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a lapsed document should stop something happening.
 *
 * The requirement's example: a supplier whose insurance certificate has expired cannot take new
 * bookings until it is renewed. That rule belongs here rather than in Supplier Management, because
 * the same question gets asked of an employee whose work permit lapsed and a vehicle whose licence
 * did, and one answer is easier to trust than three.
 *
 * Two things make this safe to put in front of real processes.
 *
 * It only considers documents somebody deliberately marked as blocking. A repository where any
 * expired file could halt a payment run is a repository that halts payment runs by accident, and
 * the person who uploaded a superseded quote three years ago did not sign up for that.
 *
 * And it fails open. If this throws — a missing module, a renamed table — the caller gets "not
 * blocked" rather than an exception, because a document module having a bad day must not stop a
 * supplier being paid. The alternative is an outage in one place becoming an outage everywhere.
 */
class DocumentCompliance
{
    /**
     * The blocking documents that have lapsed for a record.
     *
     * @return \Illuminate\Support\Collection<int, Document>
     */
    public function blockers(Model $record): \Illuminate\Support\Collection
    {
        try {
            return Document::query()
                ->whereIn('id', DocumentLink::query()
                    ->where('linkable_type', $record::class)
                    ->where('linkable_id', $record->getKey())
                    ->select('document_id'))
                ->where('blocks_when_expired', true)
                ->where('status', Document::STATUS_ACTIVE)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', now())
                ->get();
        } catch (\Throwable) {
            // Fail open. See the class comment: this gates other people's work.
            return collect();
        }
    }

    /** Whether anything lapsed is standing in the way. */
    public function isBlocked(Model $record): bool
    {
        return $this->blockers($record)->isNotEmpty();
    }

    /**
     * A sentence explaining the hold, or null when there is none.
     *
     * Names the documents, because "blocked by an expired document" sends somebody hunting through
     * a library while "Insurance certificate expired 12 March" tells them what to go and get.
     */
    public function reason(Model $record): ?string
    {
        $blockers = $this->blockers($record);

        if ($blockers->isEmpty()) {
            return null;
        }

        return __('document_management.compliance.blocked', [
            'documents' => $blockers
                ->map(fn (Document $d) => $d->title . ' (' . __('document_management.compliance.expired_on', [
                    'date' => $d->expiry_date?->toFormattedDateString(),
                ]) . ')')
                ->implode(', '),
        ]);
    }

    /**
     * Refuses the action when something has lapsed.
     *
     * For the call site that wants a guard rather than a question — the shape most callers want,
     * and it keeps the decision to enforce visible at the point of use rather than buried.
     *
     * @throws \RuntimeException
     */
    public function guard(Model $record): void
    {
        $reason = $this->reason($record);

        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }
    }
}
