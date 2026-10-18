<?php

namespace App\Modules\Operations\DocumentManagement\Notifications;

use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This needs your approval."
 *
 * The deadline is in the message when there is one, because the first thing somebody decides on
 * seeing an approval request is whether it can wait until tomorrow.
 */
class ApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly DocumentApprovalRequest $request,
        public readonly DocumentApprovalAssignment $assignment,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        // An approval somebody is waiting on is worth a message on a phone, where the platform is
        // set up for it and the approver has a number.
        if (config('services.whatsapp.access_token')
            && config('services.whatsapp.phone_number_id')
            && filled($notifiable->routeNotificationFor('whatsapp', $this) ?? null)) {
            $channels[] = \App\Modules\HR\Hr\Notifications\Channels\WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->request->document;

        $mail = (new MailMessage)
            ->subject(__('document_management.approvals.mail_subject', ['title' => $document->title]))
            ->greeting(__('document_management.approvals.mail_greeting'))
            ->line(__('document_management.approvals.mail_line', [
                'title' => $document->title,
                'step' => $this->assignment->step_name,
            ]));

        if ($this->assignment->due_at) {
            $mail->line(__('document_management.approvals.mail_due', [
                'date' => $this->assignment->due_at->toFormattedDateString(),
            ]));
        }

        // Said when it applies, so somebody handed a request knows why it reached them.
        if ($this->assignment->delegated_to) {
            $mail->line(__('document_management.approvals.mail_delegated'));
        }

        return $mail->action(
            __('document_management.approvals.mail_action'),
            url('/document-management/documents/' . $document->uuid),
        );
    }

    /**
     * The same request, short enough to read on a phone.
     *
     * Required, not optional: the channel checks for this method and returns silently without it,
     * so declaring the channel and not writing this would have queued a message that was never
     * sent and recorded as delivered.
     */
    public function toWhatsApp(object $notifiable): string
    {
        $document = $this->request->document;

        $line = __('document_management.approvals.mail_line', [
            'title' => $document->title,
            'step' => $this->assignment->step_name,
        ]);

        $due = $this->assignment->due_at
            ? ' ' . __('document_management.approvals.mail_due', ['date' => $this->assignment->due_at->toFormattedDateString()])
            : '';

        return trim($line . $due . "\n" . url('/document-management/documents/' . $document->uuid));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_approval',
            'document_uuid' => $this->request->document->uuid,
            'title' => $this->request->document->title,
            'step' => $this->assignment->step_name,
            'due_at' => $this->assignment->due_at?->toDateString(),
            'delegated' => $this->assignment->delegated_to !== null,
        ];
    }
}
