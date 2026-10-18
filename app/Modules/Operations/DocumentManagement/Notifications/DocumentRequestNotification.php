<?php

namespace App\Modules\Operations\DocumentManagement\Notifications;

use App\Modules\Operations\DocumentManagement\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Somebody needs a document from you."
 *
 * The subject line carries what is wanted and by when, because this is a message people receive
 * while doing something else and act on later, if at all. "Document request" tells them nothing and
 * gets archived; "Your ID copy is needed by 12 September" is a task.
 *
 * A reminder says so. The same message arriving a second time with no acknowledgement that it is
 * the second time reads as a system that has lost track, and people start ignoring it.
 */
class DocumentRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly DocumentRequest $request,
        public readonly bool $reminder = false,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->request->due_at?->toFormattedDateString();

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->line(__($this->reminder
                ? 'document_management.requests.mail_reminder'
                : 'document_management.requests.mail_line', [
                    'who' => $this->askerName(),
                    'what' => $this->request->title,
                ]));

        if ($this->request->description) {
            $mail->line($this->request->description);
        }

        if ($due) {
            $mail->line(__('document_management.requests.mail_due', ['date' => $due]));
        }

        return $mail->action(
            __('document_management.requests.mail_action'),
            url('/document-management/requests'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_request',
            'request_uuid' => $this->request->uuid,
            'title' => $this->request->title,
            'by' => $this->askerName(),
            'due_at' => $this->request->due_at?->toDateString(),
            'reminder' => $this->reminder,
            'url' => '/document-management/requests',
        ];
    }

    private function subject(): string
    {
        $key = $this->request->due_at
            ? 'document_management.requests.mail_subject_due'
            : 'document_management.requests.mail_subject';

        return __($key, [
            'what' => $this->request->title,
            'date' => $this->request->due_at?->toFormattedDateString() ?? '',
        ]);
    }

    private function askerName(): string
    {
        $asker = $this->request->requestedBy;

        if (! $asker) {
            return __('document_management.comments.somebody');
        }

        $person = $asker->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $asker->email;
    }
}
