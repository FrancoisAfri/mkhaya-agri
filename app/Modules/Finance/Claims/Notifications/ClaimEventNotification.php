<?php

namespace App\Modules\Finance\Claims\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClaimEventNotification extends Notification
{
    public function __construct(
        public readonly string  $event,
        public readonly string  $title,
        public readonly string  $message,
        public readonly ?string $url = null,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello,')
            ->line($this->message);

        if ($this->url) {
            $mail->action('View Claim', url($this->url));
        }

        return $mail->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'    => 'claim_' . $this->event,
            'title'   => $this->title,
            'message' => $this->message,
            'url'     => $this->url,
        ];
    }
}
