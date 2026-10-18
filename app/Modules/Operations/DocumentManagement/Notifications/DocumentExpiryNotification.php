<?php

namespace App\Modules\Operations\DocumentManagement\Notifications;

use App\Modules\Operations\DocumentManagement\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This is about to expire", or "this has expired".
 *
 * Written to be actionable in the subject line. Somebody scanning a full inbox needs to know which
 * document and how long they have without opening anything — "Tax clearance certificate expires in
 * 30 days" is a different message from "Document reminder".
 */
class DocumentExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly int $days,
    ) {
    }

    /**
     * Where this goes.
     *
     * The in-app notification and the email always. WhatsApp only where the platform is configured
     * for it and the recipient has a number — an expiring tax clearance is exactly the kind of
     * thing somebody needs on their phone, and exactly the kind of thing nobody reads in an inbox
     * they check on Mondays.
     *
     * Added rather than substituted: a channel that silently replaced the email would mean somebody
     * who changed their number stops being told at all.
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if ($this->whatsAppAvailable($notifiable)) {
            $channels[] = \App\Modules\HR\Hr\Notifications\Channels\WhatsAppChannel::class;
        }

        return $channels;
    }

    /** The same warning, short enough to read on a phone without opening anything. */
    public function toWhatsApp(object $notifiable): string
    {
        $date = $this->document->expiry_date?->toFormattedDateString();

        return trim(
            $this->headline()
            . ($date ? ' (' . $date . ')' : '')
            . "\n" . url('/document-management/documents/' . $this->document->uuid)
        );
    }

    /**
     * Whether a WhatsApp message can actually be delivered.
     *
     * Both halves are checked here rather than left to the channel: a channel that returns silently
     * looks identical to one that sent, and "the reminder went out" would be recorded either way.
     */
    private function whatsAppAvailable(object $notifiable): bool
    {
        if (! config('services.whatsapp.access_token') || ! config('services.whatsapp.phone_number_id')) {
            return false;
        }

        return filled($notifiable->routeNotificationFor('whatsapp', $this) ?? null);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->document->title;
        $date = $this->document->expiry_date?->toFormattedDateString();

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting(__('document_management.expiry.greeting'))
            ->line($this->headline());

        if ($this->document->document_number) {
            $mail->line(__('document_management.expiry.reference', ['number' => $this->document->document_number]));
        }

        if ($date) {
            $mail->line(__('document_management.expiry.date_line', ['date' => $date]));
        }

        // Blocking is the part somebody needs to act on soonest, so it is said before the link
        // rather than after it.
        if ($this->document->blocks_when_expired) {
            $mail->line(__('document_management.expiry.blocking_warning'));
        }

        return $mail
            ->action(__('document_management.expiry.open'), url('/document-management/documents/' . $this->document->uuid))
            ->line(__('document_management.expiry.replace_hint'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_expiry',
            'document_uuid' => $this->document->uuid,
            'title' => $this->document->title,
            'days' => $this->days,
            'expiry_date' => $this->document->expiry_date?->toDateString(),
            'blocking' => (bool) $this->document->blocks_when_expired,
            'message' => $this->headline(),
        ];
    }

    private function subject(): string
    {
        return $this->days === 0
            ? __('document_management.expiry.subject_today', ['title' => $this->document->title])
            : __('document_management.expiry.subject_days', [
                'title' => $this->document->title,
                'days' => $this->days,
            ]);
    }

    private function headline(): string
    {
        return $this->days === 0
            ? __('document_management.expiry.headline_today', ['title' => $this->document->title])
            : __('document_management.expiry.headline_days', [
                'title' => $this->document->title,
                'days' => $this->days,
            ]);
    }
}
