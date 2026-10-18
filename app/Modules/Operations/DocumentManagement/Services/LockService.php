<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Checking a document out, and putting it back.
 *
 * Versioning meant no edit could destroy an earlier one. It never stopped two people working on the
 * same document at once, and it cannot — the loss happens before either of them uploads. Two people
 * download the contract on Monday, both spend the afternoon on their copy, and the second upload
 * buries the first person's work in a version history nobody reads.
 *
 * Three things follow from a lock being a claim rather than a permission.
 *
 * It expires. Somebody who checks a document out and then goes on leave must not freeze it until
 * they are back, so every lock carries an end and a lapsed one simply stops counting. Checked when
 * it is read rather than swept by a scheduled job, for the same reason grants are: a document
 * nobody can edit because a cron did not run is a fault with no visible cause.
 *
 * It can be broken. Somebody has to be able to take a document back from a colleague who left the
 * company still holding it, and refusing that would mean the only remedy was a database edit.
 * Breaking a lock is recorded, because the person who loses it deserves to know who took it.
 *
 * It is not access control. A lock says who is working on something, not who may see it, and it
 * never widens what somebody can do — a person who could not edit a document cannot edit it by
 * checking it out first.
 */
class LockService
{
    public function __construct(private readonly DocumentService $documents) {}

    /** How long a lock lasts before it lapses on its own. */
    public function hours(): int
    {
        return max(1, (int) (DocumentSetting::current()->lock_hours ?? 24));
    }

    /**
     * Whether this document is currently claimed, and by whom.
     *
     * Returns null for a lock that has lapsed, so callers never have to remember to check the date
     * as well — forgetting that check is how a lapsed lock would go on blocking people.
     */
    public function holder(Document $document): ?array
    {
        if ($document->locked_by === null) {
            return null;
        }

        $expires = $document->lock_expires_at;

        if ($expires !== null && Carbon::parse($expires)->isPast()) {
            return null;
        }

        return [
            'user_id' => (int) $document->locked_by,
            'since' => $document->locked_at,
            'until' => $expires,
            'note' => $document->lock_note,
        ];
    }

    /** Whether this person is the one holding it. */
    public function heldBy(Document $document, ?User $user): bool
    {
        $holder = $this->holder($document);

        return $holder !== null && $user !== null && $holder['user_id'] === (int) $user->id;
    }

    /**
     * Whether somebody else's claim stands in this person's way.
     *
     * The question every write asks. Phrased as "blocks" rather than "is locked" because a document
     * this person checked out themselves is locked and does not block them, and confusing the two
     * would lock people out of their own work.
     */
    public function blocks(Document $document, ?User $user): bool
    {
        $holder = $this->holder($document);

        if ($holder === null) {
            return false;
        }

        return $user === null || $holder['user_id'] !== (int) $user->id;
    }

    /**
     * Claims the document.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function checkOut(Document $document, User $user, ?string $note = null): array
    {
        if ($this->blocks($document, $user)) {
            return ['ok' => false, 'error' => __('document_management.lock.held_by_another')];
        }

        /*
         * Claimed with a conditional update rather than a read followed by a write. Two people
         * pressing Check out at the same moment both pass the check above; only one of them can win
         * this, and the loser is told the truth instead of both being told they hold it.
         */
        $now = Carbon::now();
        $until = $now->copy()->addHours($this->hours());

        $claimed = DB::table('documents')
            ->where('id', $document->id)
            ->where(fn ($q) => $q->whereNull('locked_by')
                ->orWhere('locked_by', $user->id)
                ->orWhere('lock_expires_at', '<=', $now))
            ->update([
                'locked_by' => $user->id,
                'locked_at' => $now,
                'lock_expires_at' => $until,
                'lock_note' => $note,
            ]);

        if ($claimed === 0) {
            return ['ok' => false, 'error' => __('document_management.lock.held_by_another')];
        }

        $document->forceFill([
            'locked_by' => $user->id,
            'locked_at' => $now,
            'lock_expires_at' => $until,
            'lock_note' => $note,
        ])->syncOriginal();

        $this->documents->record($document, DocumentEvent::CHECKED_OUT, null, [
            'until' => $until->toDayDateTimeString(),
            'note' => $note,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Puts it back.
     *
     * @param bool $force somebody taking it back from the person holding it
     * @return array{ok: bool, error: ?string}
     */
    public function checkIn(Document $document, User $user, bool $force = false): array
    {
        $holder = $this->holder($document);

        if ($holder === null) {
            // Not an error worth stopping for: the lock lapsed, or somebody else already released
            // it, and in both cases the document is now exactly where the person wanted it.
            return ['ok' => true, 'error' => null];
        }

        if ($holder['user_id'] !== (int) $user->id && ! $force) {
            return ['ok' => false, 'error' => __('document_management.lock.held_by_another')];
        }

        $broken = $holder['user_id'] !== (int) $user->id;

        $this->release($document);

        $this->documents->record(
            $document,
            $broken ? DocumentEvent::LOCK_BROKEN : DocumentEvent::CHECKED_IN,
            null,
            $broken ? ['from' => $this->nameOf($holder['user_id'])] : [],
        );

        return ['ok' => true, 'error' => null];
    }

    /**
     * Clears the claim without recording anything.
     *
     * Used where the lock's purpose has been served rather than abandoned — a new version was
     * uploaded, so the person is finished with it. The upload is already in the history, and a
     * "checked in" line beside it would be the same fact said twice.
     */
    public function release(Document $document): void
    {
        $document->forceFill([
            'locked_by' => null,
            'locked_at' => null,
            'lock_expires_at' => null,
            'lock_note' => null,
        ])->save();
    }

    /** What this person currently has checked out, for showing them what they are still holding. */
    public function heldByUser(?User $user)
    {
        if (! $user) {
            return Document::query()->whereRaw('1 = 0');
        }

        return Document::query()
            ->where('locked_by', $user->id)
            ->where(fn ($q) => $q->whereNull('lock_expires_at')->orWhere('lock_expires_at', '>', Carbon::now()));
    }

    /** Everything currently checked out, for whoever has to unstick things. */
    public function outstanding()
    {
        return Document::query()
            ->whereNotNull('locked_by')
            ->where(fn ($q) => $q->whereNull('lock_expires_at')->orWhere('lock_expires_at', '>', Carbon::now()))
            ->orderBy('locked_at');
    }

    private function nameOf(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->with('person')->find($userId);

        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
