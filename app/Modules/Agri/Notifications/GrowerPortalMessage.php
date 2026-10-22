<?php

namespace App\Modules\Agri\Notifications;

use App\Modules\Agri\Support\AgriMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something the company tells a contracted grower: the portal invitation, a sign-in code, or a new
 * notice. By email when the channel is email, else as a WhatsApp / SMS text to the grower's mobile
 * (GrowerTextChannel). Emails wear the company's own name where it is white-labelled.
 */
class GrowerPortalMessage extends Notification
{
    public function __construct(
        public readonly string $channel,          // email | whatsapp | sms
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionText = null,
        public readonly ?string $code = null,      // a sign-in code, when this is one (kept out of logs)
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channel === 'email' ? ['mail'] : [GrowerTextChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->text);
        if ($this->actionUrl) {
            $mail->action($this->actionText ?? $this->actionUrl, $this->actionUrl);
        }

        return AgriMail::brand($mail, $notifiable->company_id ?? null);
    }

    public function toText(object $notifiable): string
    {
        return trim($this->text.($this->actionUrl ? ' '.$this->actionUrl : ''));
    }
}
