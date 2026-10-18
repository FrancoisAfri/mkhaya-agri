<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Working out when a document stops being worth keeping, and asking somebody about it.
 *
 * The one thing this never does is destroy anything. A retention schedule that deletes on a timer
 * is indistinguishable, afterwards, from a bug that deleted things — the organisation cannot say
 * who authorised it, and "the system did it automatically" is not an answer to a subpoena. So the
 * sweep computes dates, marks what has come due, and stops. Destruction stays where it already is:
 * a permission, a route, and a person.
 *
 * The date is recomputed rather than set once. A document whose effective_date is corrected, or
 * which moves to a category with a different schedule, must move with it — a disposal date frozen
 * at upload time is wrong the moment anybody edits the metadata it was derived from.
 */
class RetentionService
{
    /**
     * The dates a schedule can run from.
     *
     * Each is the answer to a different question about when a document stopped mattering. Expiry is
     * the sharpest of them — an insurance certificate's retention runs from when the cover ended,
     * not from when somebody happened to upload the PDF — which is why it is the default where a
     * category has an expiry-bearing document type.
     */
    public const BASIS_DOCUMENT_DATE = 'document_date';
    public const BASIS_EFFECTIVE_DATE = 'effective_date';
    public const BASIS_EXPIRY_DATE = 'expiry_date';
    public const BASIS_CREATED_AT = 'created_at';

    public const BASES = [
        self::BASIS_DOCUMENT_DATE,
        self::BASIS_EFFECTIVE_DATE,
        self::BASIS_EXPIRY_DATE,
        self::BASIS_CREATED_AT,
    ];

    /** Waiting for somebody to look at it. */
    public const STATE_DUE = 'due';

    /** Looked at, and the answer was keep it. It leaves the queue and its date is pushed out. */
    public const STATE_RETAINED = 'retained';

    /** Looked at, and the answer was destroy it. Destruction itself is still a separate act. */
    public const STATE_APPROVED = 'approved';

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * When this document becomes due, or null if nothing says it ever does.
     *
     * Null is returned for three different situations that all mean "not scheduled": the category
     * has no rule, the document has no category, or the date the rule runs from is not filled in.
     * The last one matters — a category retaining "seven years from the effective date" cannot say
     * anything at all about a document whose effective date is blank, and guessing at the upload
     * date instead would quietly destroy it years early.
     */
    public function dueDateFor(Document $document): ?Carbon
    {
        $category = $document->relationLoaded('category')
            ? $document->category
            : ($document->category_id ? DocumentCategory::withoutGlobalScopes()->find($document->category_id) : null);

        if (! $category || ! $category->retention_years) {
            return null;
        }

        $basis = in_array($category->retention_basis, self::BASES, true)
            ? $category->retention_basis
            : self::BASIS_CREATED_AT;

        $from = $document->{$basis};

        if (! $from) {
            return null;
        }

        return Carbon::parse($from)->copy()->addYears((int) $category->retention_years)->startOfDay();
    }

    /**
     * Recomputes disposal dates and moves newly due documents into the queue.
     *
     * Returns what it did rather than logging it, so the command can print a line and the tests can
     * assert on something other than side effects.
     *
     * @return array{scanned: int, dated: int, became_due: int, held: int}
     */
    public function sweep(): array
    {
        $counts = ['scanned' => 0, 'dated' => 0, 'became_due' => 0, 'held' => 0];
        $today = Carbon::now()->startOfDay();

        // Tenant-scoped deliberately: the command drives this once per company so row-level
        // security is engaged. A sweep that stripped the scope would read every company's documents
        // through one query and mark one organisation's files due on another's schedule.
        Document::query()
            ->whereNotNull('category_id')
            // A document already reviewed has had its answer; the sweep does not ask again.
            ->where(function (Builder $q) {
                $q->whereNull('disposal_state')->orWhere('disposal_state', self::STATE_DUE);
            })
            ->with('category')
            ->chunkById(200, function ($documents) use (&$counts, $today) {
                foreach ($documents as $document) {
                    $counts['scanned']++;

                    $due = $this->dueDateFor($document);

                    if ($due?->toDateString() !== $document->disposal_due_at?->toDateString()) {
                        $document->forceFill(['disposal_due_at' => $due])->save();
                        $counts['dated']++;
                    }

                    if ($due === null || $due->gt($today) || $document->disposal_state === self::STATE_DUE) {
                        continue;
                    }

                    /*
                     * A held document is dated like any other but never enters the queue. Putting it
                     * there would present somebody with a destroy button for a document that must
                     * not be destroyed, and the only thing standing between them would be an error
                     * message after the fact.
                     */
                    if ($document->is_legally_sensitive) {
                        $counts['held']++;

                        continue;
                    }

                    $document->forceFill(['disposal_state' => self::STATE_DUE])->save();
                    $this->documents->record($document, DocumentEvent::DISPOSAL_DUE, null, [
                        'due_at' => $due->toDateString(),
                        'category' => $document->category?->name,
                    ]);

                    $counts['became_due']++;
                }
            });

        return $counts;
    }

    /**
     * Archives what has passed its active life, without asking.
     *
     * Safe to do unattended because archiving is reversible and loses nothing: the document leaves
     * the default listing, stays searchable, and comes back whole if anybody restores it. That is
     * housekeeping, and it is the half of §17 that can honestly be automated.
     *
     * Documents under legal hold are skipped. Archiving one would not destroy it, but it would move
     * a document somebody is actively preserving out of the place they expect to find it.
     *
     * @return array{archived: int, held: int}
     */
    public function autoArchive(): array
    {
        $counts = ['archived' => 0, 'held' => 0];
        $today = Carbon::now()->startOfDay();

        Document::query()
            ->where('status', Document::STATUS_ACTIVE)
            ->whereNotNull('category_id')
            ->whereHas('category', fn ($q) => $q->where('auto_archive', true)->whereNotNull('archive_after_years'))
            ->with('category')
            ->chunkById(200, function ($documents) use (&$counts, $today) {
                foreach ($documents as $document) {
                    $due = $this->archiveDateFor($document);

                    if ($due === null || $due->gt($today)) {
                        continue;
                    }

                    if ($document->isUnderLegalHold()) {
                        $counts['held']++;

                        continue;
                    }

                    $document->forceFill([
                        'status' => Document::STATUS_ARCHIVED,
                        'archived_at' => Carbon::now(),
                        'auto_archived_at' => Carbon::now(),
                        'archive_reason' => __('document_management.retention.auto_archived'),
                    ])->save();

                    $this->documents->record($document, DocumentEvent::ARCHIVED, null, [
                        'reason' => 'retention schedule',
                        'category' => $document->category?->name,
                    ]);

                    $counts['archived']++;
                }
            });

        return $counts;
    }

    /**
     * Carries out disposals a person already approved.
     *
     * Three things stand between a document and this: the company-wide switch, the category's own,
     * and a person having approved the disposal in the first place. It only ever performs a decision
     * somebody already made — nothing here decides.
     *
     * And it stops at the recoverable delete. Permanent destruction stays behind a person with the
     * purge permission, because nothing running unattended should be able to make a document
     * unrecoverable, and a retention policy is not improved by being irreversible.
     *
     * @return array{deleted: int, held: int, waiting: int}
     */
    public function autoDelete(): array
    {
        $counts = ['deleted' => 0, 'held' => 0, 'waiting' => 0];

        $settings = DocumentSetting::current();

        if (! $settings->auto_delete_enabled) {
            return $counts;
        }

        $grace = max(0, (int) ($settings->disposal_grace_days ?? 7));
        $cutoff = Carbon::now()->subDays($grace);

        Document::query()
            ->where('disposal_state', self::STATE_APPROVED)
            ->whereNotNull('category_id')
            ->whereHas('category', fn ($q) => $q->where('auto_delete', true))
            ->with('category')
            ->chunkById(200, function ($documents) use (&$counts, $cutoff) {
                foreach ($documents as $document) {
                    // The grace period between the approval and the act, so somebody who approves a
                    // disposal by mistake on a Friday has until the following week to notice.
                    if ($document->disposal_reviewed_at === null || $document->disposal_reviewed_at->gt($cutoff)) {
                        $counts['waiting']++;

                        continue;
                    }

                    if ($document->isUnderLegalHold()) {
                        // A hold placed after the approval. The approval does not survive it.
                        $counts['held']++;

                        continue;
                    }

                    $result = $this->documents->delete($document);

                    if ($result['ok']) {
                        $counts['deleted']++;
                    }
                }
            });

        return $counts;
    }

    /** When this document is due to be archived, or null where no schedule says. */
    public function archiveDateFor(Document $document): ?Carbon
    {
        $category = $document->relationLoaded('category')
            ? $document->category
            : ($document->category_id ? DocumentCategory::withoutGlobalScopes()->find($document->category_id) : null);

        if (! $category || ! $category->archive_after_years) {
            return null;
        }

        $basis = in_array($category->retention_basis, self::BASES, true)
            ? $category->retention_basis
            : self::BASIS_CREATED_AT;

        $from = $document->{$basis};

        return $from
            ? Carbon::parse($from)->copy()->addYears((int) $category->archive_after_years)->startOfDay()
            : null;
    }

    /**
     * Keep it: the reviewer decided this document is still needed.
     *
     * The date is pushed out by the schedule's own period rather than cleared, so the question gets
     * asked again in due course instead of never. A document reviewed once and then exempt forever
     * is how a retention schedule quietly becomes a list of things nobody has thought about.
     */
    public function retain(Document $document, ?string $note = null): void
    {
        $category = $document->category ?: ($document->category_id
            ? DocumentCategory::withoutGlobalScopes()->find($document->category_id)
            : null);

        $next = $category?->retention_years
            ? Carbon::now()->startOfDay()->addYears((int) $category->retention_years)
            : null;

        DB::transaction(function () use ($document, $note, $next) {
            $document->forceFill([
                'disposal_state' => self::STATE_RETAINED,
                'disposal_reviewed_at' => Carbon::now(),
                'disposal_reviewed_by' => Auth::id(),
                'disposal_note' => $note,
                'disposal_due_at' => $next,
            ])->save();

            $this->documents->record($document, DocumentEvent::DISPOSAL_RETAINED, null, [
                'note' => $note,
                'next_review' => $next?->toDateString(),
            ]);
        });
    }

    /**
     * Approve destruction — which is a decision, not the destruction itself.
     *
     * Separating them is the point. The person who reviews a retention queue is not always the
     * person who should be permanently destroying documents, and collapsing the two would mean
     * approving is destroying for anybody who can do the first.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function approveDisposal(Document $document, ?string $note = null): array
    {
        if ($document->isUnderLegalHold()) {
            return ['ok' => false, 'error' => 'This document is under legal hold and cannot be approved for disposal.'];
        }

        DB::transaction(function () use ($document, $note) {
            $document->forceFill([
                'disposal_state' => self::STATE_APPROVED,
                'disposal_reviewed_at' => Carbon::now(),
                'disposal_reviewed_by' => Auth::id(),
                'disposal_note' => $note,
            ])->save();

            $this->documents->record($document, DocumentEvent::DISPOSAL_APPROVED, null, ['note' => $note]);
        });

        return ['ok' => true, 'error' => null];
    }

    /**
     * What is waiting to be reviewed.
     *
     * @param string $state one of the STATE_* constants
     * @return Builder<Document>
     */
    public function queue(string $state = self::STATE_DUE): Builder
    {
        return Document::query()
            ->where('disposal_state', $state)
            ->with(['category', 'folder'])
            ->orderBy('disposal_due_at');
    }

    /** @return array{due: int, approved: int, held: int, scheduled: int} */
    public function summary(): array
    {
        return [
            'due' => Document::query()->where('disposal_state', self::STATE_DUE)->count(),
            'approved' => Document::query()->where('disposal_state', self::STATE_APPROVED)->count(),
            'held' => Document::query()->where('is_legally_sensitive', true)->count(),
            'scheduled' => Document::query()->whereNotNull('disposal_due_at')->count(),
        ];
    }
}
