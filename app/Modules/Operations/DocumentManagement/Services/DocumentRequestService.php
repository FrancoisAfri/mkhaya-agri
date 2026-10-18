<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentRequest;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentRequestNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Asking people for documents, and keeping track of who has not answered.
 *
 * This is the opposite direction from the rest of the module: not somebody asking to be let in to
 * something that exists, but somebody asking for something that does not exist yet. "Send me your
 * ID copy." "We need the signed lease." "Where is the tax clearance certificate."
 *
 * That collection happens over email today and fails the same way every time: the request goes out,
 * the file comes back as an attachment, somebody files it by hand, and nobody can say which of the
 * forty people asked have actually replied. The list of who still owes what is what is being built
 * here. The upload is the easy part.
 *
 * Two decisions carry the design. The request holds the filing — folder, category, title — so the
 * person answering never has to learn the structure; they press upload and it lands where it
 * belongs. And asking forty people creates forty requests rather than one, because a request
 * covering several people cannot be half-answered and the queue would be useless the moment the
 * first person replied.
 */
class DocumentRequestService
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Asks one or more people for a document.
     *
     * @param list<int> $userIds
     * @param array{description?: ?string, folder_id?: ?int, category_id?: ?int, due_at?: ?string} $options
     * @return list<DocumentRequest>
     */
    public function ask(string $title, array $userIds, array $options = []): array
    {
        $created = [];

        DB::transaction(function () use ($title, $userIds, $options, &$created) {
            foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                /*
                 * Asking the same person for the same thing twice is one request. Otherwise a
                 * chase-up creates a second row, the queue shows them owing two documents, and one
                 * of the two can never be answered.
                 */
                $existing = DocumentRequest::query()
                    ->pending()
                    ->where('requested_from', $userId)
                    ->where('title', $title)
                    ->first();

                if ($existing) {
                    $created[] = $existing;

                    continue;
                }

                $created[] = DocumentRequest::create([
                    'uuid' => (string) Str::uuid(),
                    'title' => $title,
                    'description' => $options['description'] ?? null,
                    'requested_from' => $userId,
                    'requested_by' => Auth::id(),
                    'folder_id' => $options['folder_id'] ?? null,
                    'category_id' => $options['category_id'] ?? null,
                    'due_at' => filled($options['due_at'] ?? null) ? Carbon::parse($options['due_at']) : null,
                    'status' => DocumentRequest::PENDING,
                ]);
            }
        });

        $this->notify($created);

        return $created;
    }

    /**
     * Answers a request with a file.
     *
     * The upload goes through the ordinary document service, so a document arriving this way is
     * scanned, versioned, extracted and audited exactly like one uploaded by hand. A second path
     * into the repository that skipped any of that would be the way to get an unscanned file in.
     *
     * @return array{document: ?Document, error: ?string}
     */
    public function fulfil(DocumentRequest $request, UploadedFile $file): array
    {
        if (! $request->isPending()) {
            return ['document' => null, 'error' => __('document_management.requests.not_pending')];
        }

        $result = $this->documents->store($file, [
            'folder_id' => $request->folder_id,
            'category_id' => $request->category_id,
            // Named for what was asked for rather than for whatever the file was called on somebody's
            // phone. "IMG_4471.jpg" filed under Employee Files is a document nobody finds again.
            'title' => $request->title,
        ]);

        if (! $result['document']) {
            return $result;
        }

        $request->forceFill([
            'status' => DocumentRequest::FULFILLED,
            'document_id' => $result['document']->id,
            'fulfilled_at' => Carbon::now(),
        ])->save();

        $this->documents->record($result['document'], DocumentEvent::REQUEST_FULFILLED, null, [
            'request' => $request->title,
            'for' => $this->nameOf($request->requestedBy),
        ]);

        return $result;
    }

    /**
     * Answers a request with a document already in the repository.
     *
     * Somebody asked for the signed lease and the signed lease is already filed. Uploading it again
     * would put a second copy in the system, and the two would then drift.
     */
    public function fulfilWith(DocumentRequest $request, Document $document): void
    {
        if (! $request->isPending()) {
            return;
        }

        $request->forceFill([
            'status' => DocumentRequest::FULFILLED,
            'document_id' => $document->id,
            'fulfilled_at' => Carbon::now(),
        ])->save();

        $this->documents->record($document, DocumentEvent::REQUEST_FULFILLED, null, [
            'request' => $request->title,
            'existing' => true,
        ]);
    }

    /** Withdraws a request that is no longer needed. */
    public function cancel(DocumentRequest $request, ?string $reason = null): void
    {
        if (! $request->isPending()) {
            return;
        }

        $request->forceFill([
            'status' => DocumentRequest::CANCELLED,
            'cancelled_at' => Carbon::now(),
            'cancel_reason' => $reason,
        ])->save();
    }

    /**
     * Asks again.
     *
     * The count is kept because "I have asked them three times" is the sentence that precedes
     * escalating it, and nobody should have to reconstruct it from their sent items.
     *
     * @param list<DocumentRequest> $requests
     */
    public function remind(array $requests): int
    {
        $sent = 0;

        foreach ($requests as $request) {
            if (! $request->isPending()) {
                continue;
            }

            $request->forceFill([
                'reminders_sent' => (int) $request->reminders_sent + 1,
                'last_reminded_at' => Carbon::now(),
            ])->save();

            $sent++;
        }

        $this->notify($requests, reminder: true);

        return $sent;
    }

    /** What this person owes. */
    public function owedBy(?User $user)
    {
        if (! $user) {
            return DocumentRequest::query()->whereRaw('1 = 0');
        }

        return DocumentRequest::query()
            ->pending()
            ->where('requested_from', $user->id)
            ->with(['requestedBy.person', 'folder', 'category'])
            ->orderByRaw('due_at is null, due_at');
    }

    /** What this person is waiting on. */
    public function askedBy(?User $user)
    {
        if (! $user) {
            return DocumentRequest::query()->whereRaw('1 = 0');
        }

        return DocumentRequest::query()
            ->where('requested_by', $user->id)
            ->with(['requestedFrom.person', 'folder', 'document'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByRaw('due_at is null, due_at');
    }

    /** @param list<DocumentRequest> $requests */
    private function notify(array $requests, bool $reminder = false): void
    {
        foreach ($requests as $request) {
            $recipient = $request->requestedFrom;

            if (! $recipient || ! $request->isPending()) {
                continue;
            }

            Notification::send($recipient, new DocumentRequestNotification($request, $reminder));
        }
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
