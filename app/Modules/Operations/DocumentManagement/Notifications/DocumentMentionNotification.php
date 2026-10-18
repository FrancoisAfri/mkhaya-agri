<?php

namespace App\Modules\Operations\DocumentManagement\Notifications;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "Somebody named you in a comment."
 *
 * The reason mentions are worth building at all: a mention that only renders in bold is a mention
 * nobody sees, and the person who wrote it believes they have asked a question and is waiting for
 * an answer that is never coming.
 *
 * What it deliberately does not carry is the document's contents. Naming a colleague on a
 * confidential document must not be a way of telling them what it says — this carries the title, an
 * extract of the remark, and a link, and the link lands them wherever the permissions put them,
 * which may well be the page asking whether they would like to request access.
 */
class DocumentMentionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly DocumentComment $comment,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('document_management.comments.mail_subject', ['title' => $this->document->title]))
            ->line(__('document_management.comments.mail_line', [
                'who' => $this->authorName(),
                'title' => $this->document->title,
            ]))
            ->line('"' . $this->extract() . '"')
            ->action(
                __('document_management.comments.mail_action'),
                url('/document-management/documents/' . $this->document->uuid),
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_mention',
            'document_uuid' => $this->document->uuid,
            'title' => $this->document->title,
            'by' => $this->authorName(),
            'extract' => $this->extract(),
            'url' => '/document-management/documents/' . $this->document->uuid,
        ];
    }

    /**
     * Enough of the remark to know whether it needs answering now.
     *
     * Trimmed rather than sent whole: the point is to get somebody to open the document, and a
     * comment quoted in full in an email is the comment leaving the repository.
     */
    private function extract(): string
    {
        return Str::limit(trim($this->comment->body), 180);
    }

    private function authorName(): string
    {
        $author = $this->comment->author;

        if (! $author) {
            return __('document_management.comments.somebody');
        }

        $person = $author->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $author->email;
    }
}
