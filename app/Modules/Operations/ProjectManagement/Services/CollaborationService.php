<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\DocumentStore;
use App\Modules\Operations\ProjectManagement\Support\Providers\DocumentContents;
use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAttachment;
use App\Modules\Operations\ProjectManagement\Models\TaskComment;
use App\Modules\Operations\ProjectManagement\Models\TaskWatcher;
use App\Modules\Operations\ProjectManagement\Notifications\TaskMentionNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * What happens around a task: checklists, comments, watchers and files (PM-1121).
 *
 * None of it is evidence. A comment saying the slab was poured does not make the slab poured — that
 * claim belongs in the ledger, where it is hashed and cannot be quietly edited afterwards
 * (PM-ADR-002). Keeping the two apart is what lets a comment stay editable, which is the whole
 * point of a conversation.
 */
class CollaborationService
{
    /** A comment longer than this is a document, and belongs in one. */
    public const MAX_COMMENT_LENGTH = 10000;

    public const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /**
     * Resolved per call, not injected: which store is installed is a property of the tenant, and a
     * constructor-injected one would freeze the first tenant's answer for the whole worker.
     */
    private function documents(): DocumentStore
    {
        return $this->providers->for(DocumentStore::class);
    }

    // ── Checklists ───────────────────────────────────────────────────────────

    public function addChecklistItem(Task $task, string $text, ?int $position = null): ChecklistItem
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('A checklist item needs some text.');
        }

        return ChecklistItem::create([
            'project_id' => $task->project_id,
            'task_id'    => $task->id,
            'text'       => $text,
            'position'   => $position ?? ((int) ChecklistItem::where('task_id', $task->id)->max('position') + 1),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Tick or untick an item.
     *
     * Ticking does not move the task's progress on its own: a checklist is the assignee's own
     * working list, and letting it drive the reported percentage would make progress a function of
     * how finely someone chose to break their notes down.
     */
    public function setChecklistItemDone(ChecklistItem $item, bool $done): ChecklistItem
    {
        $item->forceFill([
            'is_done' => $done,
            'done_by' => $done ? Auth::id() : null,
            'done_at' => $done ? now() : null,
        ])->save();

        return $item;
    }

    /**
     * Reorder a task's checklist from an ordered list of item uuids.
     *
     * @param  list<string>  $uuids
     */
    public function reorderChecklist(Task $task, array $uuids): void
    {
        DB::transaction(function () use ($task, $uuids) {
            foreach (array_values($uuids) as $index => $uuid) {
                ChecklistItem::where('task_id', $task->id)->where('uuid', $uuid)
                    ->update(['position' => $index + 1]);
            }
        });
    }

    public function deleteChecklistItem(ChecklistItem $item): void
    {
        $item->delete();
    }

    // ── Comments ─────────────────────────────────────────────────────────────

    /**
     * Post a comment, subscribing the author and anyone they named.
     *
     * @param  list<int>  $mentionedUserIds  resolved by the caller, stored as they were
     */
    public function comment(Task $task, string $body, array $mentionedUserIds = [], ?TaskComment $replyTo = null): TaskComment
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A comment needs something in it.');
        }

        if (mb_strlen($body) > self::MAX_COMMENT_LENGTH) {
            throw new InvalidArgumentException(
                'A comment is limited to ' . number_format(self::MAX_COMMENT_LENGTH) . ' characters; attach a document instead.'
            );
        }

        if ($replyTo && $replyTo->task_id !== $task->id) {
            throw new InvalidArgumentException('That comment is on a different task.');
        }

        $comment = DB::transaction(function () use ($task, $body, $mentionedUserIds, $replyTo) {
            $comment = TaskComment::create([
                'project_id'        => $task->project_id,
                'task_id'           => $task->id,
                // One level of threading: a reply to a reply attaches to the same parent, so a
                // conversation cannot grow an indentation staircase nobody can read on a phone.
                'parent_comment_id' => $replyTo?->parent_comment_id ?? $replyTo?->id,
                'author_id'         => Auth::id(),
                'body'              => $body,
                'mentions'          => array_values(array_unique(array_map('intval', $mentionedUserIds))),
            ]);

            if ($author = Auth::id()) {
                $this->watch($task, $author, 'manual');
            }

            foreach ($comment->mentions as $userId) {
                $this->watch($task, $userId, 'mention');
            }

            return $comment;
        });

        /*
         * Outside the transaction: an email sent from one that later rolls back cannot be unsent,
         * and the recipient would be holding a question about a comment nobody can find.
         */
        $this->announceMentions($task, $comment);

        return $comment;
    }

    /**
     * Tell the people a comment named that it named them.
     *
     * Tagging somebody already subscribed them to the task, so they would hear about whatever
     * happened next — and never about the thing that named them. A question asked of somebody
     * directly and delivered nowhere is the most reliable way to not get an answer.
     *
     * Never the author: being emailed about your own comment is noise, and it is the noise that
     * makes the rest of the channel ignorable.
     */
    private function announceMentions(Task $task, TaskComment $comment): void
    {
        $userIds = collect($comment->mentions)
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) Auth::id())
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $author = Auth::id() ? User::query()->with('person')->find(Auth::id()) : null;

        // Names live on the HR record on this platform; there is no `users.name` column.
        $authorName = $author?->person
            ? trim($author->person->first_name . ' ' . $author->person->surname) ?: $author->email
            : $author?->email;

        $recipients = User::query()->whereIn('id', $userIds)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TaskMentionNotification($task, $comment, $authorName));
        }
    }

    /** Edit a comment in place, stamping when — the body is the current text, `edited_at` is the tell. */
    public function editComment(TaskComment $comment, string $body): TaskComment
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A comment needs something in it.');
        }

        $comment->forceFill(['body' => $body, 'edited_at' => now()])->save();

        return $comment;
    }

    public function deleteComment(TaskComment $comment): void
    {
        DB::transaction(function () use ($comment) {
            // Replies go with the comment they answer; an orphaned "yes, agreed" is noise.
            TaskComment::where('parent_comment_id', $comment->id)->delete();
            $comment->delete();
        });
    }

    // ── Watchers ─────────────────────────────────────────────────────────────

    /**
     * Subscribe someone, keeping the reason they first became a watcher.
     *
     * An existing subscription is returned untouched rather than re-sourced: how someone came to be
     * watching is history. A *new* mention does resubscribe someone who had unwatched — that is
     * another person deliberately calling them back in, which is different from the same event
     * re-adding a watch they just cleared.
     */
    public function watch(Task $task, int $userId, string $source = 'manual'): TaskWatcher
    {
        if (! in_array($source, TaskWatcher::SOURCES, true)) {
            throw new InvalidArgumentException("'{$source}' is not a way to become a watcher.");
        }

        $existing = TaskWatcher::where('task_id', $task->id)->where('user_id', $userId)->first();

        if ($existing) {
            return $existing;
        }

        return TaskWatcher::create([
            'project_id' => $task->project_id,
            'task_id'    => $task->id,
            'user_id'    => $userId,
            'source'     => $source,
        ]);
    }

    public function unwatch(Task $task, int $userId): void
    {
        TaskWatcher::where('task_id', $task->id)->where('user_id', $userId)->delete();
    }

    public function setMuted(Task $task, int $userId, bool $muted): void
    {
        TaskWatcher::where('task_id', $task->id)->where('user_id', $userId)->update(['is_muted' => $muted]);
    }

    /** @return list<int> the user ids that should hear about a change, excluding the person making it */
    public function notifiableWatchers(Task $task, ?int $except = null): array
    {
        return TaskWatcher::where('task_id', $task->id)
            ->where('is_muted', false)
            ->when($except, fn ($q) => $q->where('user_id', '!=', $except))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    /**
     * Attach a file. The bytes go to whichever DocumentStore is installed — PM's own tenant-scoped
     * table, or the Documents module when that is licensed — and this table keeps only the
     * reference, so changing stores is a configuration change rather than a migration.
     */
    public function attach(Task $task, UploadedFile $file): TaskAttachment
    {
        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            throw new InvalidArgumentException(
                'That file is larger than ' . (self::MAX_ATTACHMENT_BYTES / 1024 / 1024) . ' MB.'
            );
        }

        $documentId = $this->documents()->put($task->project->uuid, $file, [
            'task_uuid'   => $task->uuid,
            'uploaded_by' => Auth::id(),
        ]);

        // Described by the store rather than by the upload: an image is recompressed on the way in,
        // so the size and mime the browser sent are not the size and mime of what is now held, and
        // a listing built from the browser's numbers would describe bytes that are gone.
        $stored = $this->documents()->describe($documentId);

        return TaskAttachment::create([
            'project_id'  => $task->project_id,
            'task_id'     => $task->id,
            'document_id' => $documentId,
            'filename'    => $stored?->label ?? $file->getClientOriginalName(),
            'mime_type'   => $stored?->meta['mime'] ?? $file->getClientMimeType(),
            'size_bytes'  => $stored?->meta['size'] ?? $file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * The bytes, when the installed store holds them — which PM's own store does, so the download
     * is served through this application under the caller's own authorisation.
     */
    public function attachmentContents(TaskAttachment $attachment): ?DocumentContents
    {
        return $this->documents()->contents($attachment->document_id);
    }

    /** A time-limited link, for a store that keeps its bytes somewhere else. */
    public function attachmentUrl(TaskAttachment $attachment): ?string
    {
        return $this->documents()->url($attachment->document_id);
    }

    public function detach(TaskAttachment $attachment): void
    {
        // The store decides what happens to the bytes: PM's own store owns them and removes them,
        // an integrated one keeps them under its module's retention rules.
        $this->documents()->delete($attachment->document_id);

        $attachment->delete();
    }
}
