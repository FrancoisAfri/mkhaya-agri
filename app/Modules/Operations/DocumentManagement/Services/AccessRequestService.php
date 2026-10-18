<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asking to be let in, and answering.
 *
 * Granting a request does not invent a second way of seeing a document: it creates the same
 * internal share anybody could have made by hand. One mechanism, one place to look when asking why
 * somebody can open something.
 */
class AccessRequestService
{
    public function __construct(private readonly InternalShareService $shares) {}

    /**
     * Records a request, or returns the one already outstanding.
     *
     * Asking twice while the first is unanswered is one request. Otherwise a queue fills with the
     * same person asking again because nothing visibly happened, and the person answering it cannot
     * tell the repeats from the new ones.
     */
    public function request(
        User $user,
        Document|DocumentFolder $subject,
        ?string $reason = null,
        string $kind = DocumentAccessRequest::KIND_ACCESS,
    ): DocumentAccessRequest {
        $isDocument = $subject instanceof Document;

        return DocumentAccessRequest::firstOrCreate(
            [
                'document_id' => $isDocument ? $subject->id : null,
                'folder_id' => $isDocument ? null : $subject->id,
                'requested_by' => $user->id,
                // Part of the key: somebody who was let in to read a document and then wants a copy
                // of it is asking a second, different question, and folding it into the first
                // request would leave the second one silently unanswered.
                'kind' => $kind,
                'status' => DocumentAccessRequest::PENDING,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'reason' => $reason,
            ],
        );
    }

    /**
     * Asks for several things at once.
     *
     * Somebody who has just been handed a job needs the Finance folder, the Legal folder and two
     * contracts — that is one conversation, not four. Asking one at a time is how the fourth is
     * forgotten, and it is also four notifications to whoever answers.
     *
     * Still one row per thing, because each is granted or refused on its own merits and a request
     * covering four folders could only ever be answered all-or-nothing.
     *
     * @param  list<Document|DocumentFolder>  $subjects
     * @return list<DocumentAccessRequest>
     */
    public function requestMany(User $user, array $subjects, ?string $reason = null, string $kind = DocumentAccessRequest::KIND_ACCESS): array
    {
        $created = [];

        DB::transaction(function () use ($user, $subjects, $reason, $kind, &$created) {
            foreach ($subjects as $subject) {
                $created[] = $this->request($user, $subject, $reason, $kind);
            }
        });

        return $created;
    }

    /**
     * Lets them in.
     *
     * A folder request grants every document filed in it at the time of the decision. Deliberately
     * not a standing rule: somebody asking to see the Procurement folder this week should not
     * silently gain everything filed there next year, and a request that quietly widened over time
     * is the kind of access nobody remembers approving.
     *
     * @return int how many documents the person can now see
     */
    public function grant(DocumentAccessRequest $request, ?string $note = null): int
    {
        if (! $request->isPending()) {
            return 0;
        }

        return DB::transaction(function () use ($request, $note) {
            $requester = $request->requestedBy;
            $granted = 0;

            $name = $this->nameOf($requester);

            /*
             * A download approval is the same grant with two things added: the right to take a copy,
             * and a date it stops. Approving a copy once should not be an approval that outlives the
             * reason for it — the alternative is a grant nobody revokes because nobody remembers it
             * was made.
             */
            $isDownload = $request->isDownload();

            $options = $isDownload
                ? [
                    'can_download' => true,
                    'expires_days' => $this->downloadWindow(),
                    'note' => __('document_management.access.download_granted_note'),
                ]
                : ['note' => __('document_management.access.granted_note')];

            if ($request->document_id && $document = Document::withoutGlobalScopes()->find($request->document_id)) {
                $this->shares->grant($document, DocumentGrant::SUBJECT_USER, $requester?->id, $name, $options);
                $granted = 1;
            }

            if ($request->folder_id) {
                $documents = Document::withoutGlobalScopes()
                    ->where('folder_id', $request->folder_id)
                    ->get();

                foreach ($documents as $document) {
                    $this->shares->grant($document, DocumentGrant::SUBJECT_USER, $requester?->id, $name, $options);
                }

                $granted = $documents->count();
            }

            $request->forceFill([
                'status' => DocumentAccessRequest::GRANTED,
                'decided_by' => Auth::id(),
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ])->save();

            return $granted;
        });
    }

    /** Turns it down. Recorded, because "we said no" is worth being able to show. */
    public function decline(DocumentAccessRequest $request, ?string $note = null): void
    {
        if (! $request->isPending()) {
            return;
        }

        $request->forceFill([
            'status' => DocumentAccessRequest::DECLINED,
            'decided_by' => Auth::id(),
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
        ])->save();
    }

    /** What is waiting to be answered. */
    public function pending()
    {
        return DocumentAccessRequest::query()
            ->pending()
            ->with(['document', 'folder', 'requestedBy.person'])
            ->latest('created_at');
    }

    /** How long an approved copy stays approved for. */
    private function downloadWindow(): int
    {
        return max(1, (int) (\App\Modules\Operations\DocumentManagement\Models\DocumentSetting::current()->download_request_days ?? 7));
    }

    private function nameOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
