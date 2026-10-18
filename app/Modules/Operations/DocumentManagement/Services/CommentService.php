<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentComment;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentMentionNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The conversation about a document, kept next to the document.
 *
 * It currently happens in email, and that is the half of the record that never arrives: the
 * contract is filed and the reason it says what it says is in somebody's inbox. Six months later
 * the clause is queried and nobody can find the exchange that produced it.
 *
 * Three things this has to get right.
 *
 * A comment can be pinned to a version, because "clause 4 is wrong" stops being true the moment
 * clause 4 is rewritten. Against 1.2 it stays true, and the reader can see it was about the old
 * wording rather than the current one.
 *
 * Naming somebody has to actually reach them. A mention that only renders in bold is a mention
 * nobody sees, and the person who wrote it believes they have asked a question.
 *
 * And a mention cannot be a way in. Naming a colleague in a comment on a confidential document
 * must not tell them what it says — the notification carries the title and a link, and the link
 * lands them wherever the permissions put them, which may be the request-access page.
 */
class CommentService
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Adds a remark, or a reply to one.
     *
     * @param list<int> $mentions user ids named in the text
     */
    public function add(
        Document $document,
        string $body,
        ?DocumentComment $parent = null,
        ?DocumentVersion $version = null,
        array $mentions = [],
    ): DocumentComment {
        return DB::transaction(function () use ($document, $body, $parent, $version, $mentions) {
            /*
             * A reply belongs to the thread, never to another reply. One level is what a side panel
             * can show and what a disagreement about a clause can be followed in; arbitrary nesting
             * produces a shape nobody can read and an indent nobody can see the end of.
             */
            $threadId = $parent?->parent_id ?? $parent?->id;

            $mentions = array_values(array_unique(array_map('intval', $mentions)));

            $comment = DocumentComment::create([
                'uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'version_id' => $version?->id,
                'parent_id' => $threadId,
                'body' => trim($body),
                'mentions' => $mentions ?: null,
                'created_by' => Auth::id(),
            ]);

            $this->documents->record($document, DocumentEvent::COMMENTED, $version, [
                'reply' => $threadId !== null,
                'mentions' => count($mentions),
            ]);

            $this->notify($document, $comment, $mentions);

            return $comment;
        });
    }

    /**
     * Marks a thread settled.
     *
     * The whole thread, not the one remark: a reply left open under a resolved opener would keep
     * the thread showing as live, which is the state this exists to clear.
     */
    public function resolve(DocumentComment $comment, User $user): void
    {
        $thread = $comment->parent_id ? DocumentComment::find($comment->parent_id) : $comment;

        if (! $thread || $thread->isResolved()) {
            return;
        }

        DB::transaction(function () use ($thread, $user) {
            $thread->forceFill([
                'resolved_at' => Carbon::now(),
                'resolved_by' => $user->id,
            ])->save();

            DocumentComment::where('parent_id', $thread->id)->update([
                'resolved_at' => Carbon::now(),
                'resolved_by' => $user->id,
            ]);

            $document = Document::withoutGlobalScopes()->find($thread->document_id);

            if ($document) {
                $this->documents->record($document, DocumentEvent::COMMENT_RESOLVED);
            }
        });
    }

    /** Reopens a thread somebody settled too early. */
    public function reopen(DocumentComment $comment): void
    {
        $thread = $comment->parent_id ? DocumentComment::find($comment->parent_id) : $comment;

        if (! $thread) {
            return;
        }

        DocumentComment::where('id', $thread->id)->orWhere('parent_id', $thread->id)
            ->update(['resolved_at' => null, 'resolved_by' => null]);
    }

    /**
     * The threads on a document, replies attached, open ones first.
     *
     * Open first rather than newest first: the reason somebody opens this panel is to see what is
     * still outstanding, and a settled thread from this morning is not it.
     *
     * @return list<array<string, mixed>>
     */
    public function threadsFor(Document $document): array
    {
        $comments = DocumentComment::query()
            ->where('document_id', $document->id)
            ->with(['author.person', 'version'])
            ->orderBy('created_at')
            ->get();

        $replies = $comments->whereNotNull('parent_id')->groupBy('parent_id');

        return $comments
            ->whereNull('parent_id')
            ->sortBy([
                fn ($a, $b) => ($a->isResolved() ? 1 : 0) <=> ($b->isResolved() ? 1 : 0),
                fn ($a, $b) => $b->created_at <=> $a->created_at,
            ])
            ->map(fn (DocumentComment $comment) => $this->shape($comment, $replies->get($comment->id, collect())))
            ->values()
            ->all();
    }

    /** How many conversations on this document are still live. */
    public function openCount(Document $document): int
    {
        return DocumentComment::query()
            ->where('document_id', $document->id)
            ->threads()
            ->open()
            ->count();
    }

    /** @param \Illuminate\Support\Collection<int, DocumentComment> $replies */
    private function shape(DocumentComment $comment, $replies): array
    {
        return [
            'uuid' => $comment->uuid,
            'body' => $comment->body,
            'by' => $this->nameOf($comment->author),
            'at' => $comment->created_at?->diffForHumans(),
            'version' => $comment->version?->label(),
            'resolved' => $comment->isResolved(),
            'resolved_by' => $this->nameOf(User::find($comment->resolved_by)),
            'replies' => $replies->map(fn (DocumentComment $reply) => [
                'uuid' => $reply->uuid,
                'body' => $reply->body,
                'by' => $this->nameOf($reply->author),
                'at' => $reply->created_at?->diffForHumans(),
            ])->values()->all(),
        ];
    }

    /**
     * Tells the people who were named.
     *
     * Only the people named, and never the author naming themselves: a notification about your own
     * comment is noise, and noise is how somebody learns to ignore the ones that matter.
     *
     * @param list<int> $mentions
     */
    private function notify(Document $document, DocumentComment $comment, array $mentions): void
    {
        /*
         * Constrained to the document's own company, not left to the User model's global scope.
         * The ids come from the client, and that scope steps aside for a platform administrator —
         * without this, a crafted id would email another tenant's staff the document's title.
         */
        $recipients = User::query()
            ->whereIn('id', array_diff($mentions, [(int) Auth::id()]))
            ->where('status', 1)
            ->when($document->company_id, fn ($query) => $query->where('company_id', $document->company_id))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new DocumentMentionNotification($document, $comment));
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
