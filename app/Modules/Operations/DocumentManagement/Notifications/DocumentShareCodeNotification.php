<?php

namespace App\Modules\Operations\DocumentManagement\Notifications;

use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The code that proves somebody holds the mailbox a link was sent to.
 *
 * Carries the code and nothing else about the document — not its title, not who shared it. This
 * email is sent to whatever address the link was made out to, and if the link has gone astray this
 * is the message that arrives at the wrong desk. It has to be useless to whoever receives it in
 * error, which means saying as little as it possibly can.
 *
 * Not queued behind the ordinary queue driver's delay either — the person is sitting in front of
 * the page waiting for it, and a code that arrives in four minutes has already been given up on.
 */
class DocumentShareCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly DocumentShare $share,
        public readonly string $code,
    ) {
    }

    public function via(object $notifiable): array
    {
        // Mail only. There is no account behind this address — that is the entire premise of an
        // external share — so there is nowhere for a database notification to be read.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('document_management.sharing.code_subject'))
            ->line(__('document_management.sharing.code_line'))
            ->line('**' . $this->code . '**')
            ->line(__('document_management.sharing.code_expiry'))
            ->line(__('document_management.sharing.code_ignore'));
    }
}
