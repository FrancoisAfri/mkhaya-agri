<?php

namespace App\Modules\Operations\Manufacturing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A manufacturing alert, in-app and by email where the rule asks for it (MFG-020). */
class ManufacturingAlert extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels */
    public function __construct(
        public readonly string $eventType,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $url,
        public readonly array $channels,
        public readonly int $level = 0,
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter($this->channels, fn ($c) => $c === 'database' || ($c === 'mail' && ! empty($notifiable->email))));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'mfg_'.strtolower($this->eventType), 'title' => $this->title, 'message' => $this->message, 'url' => $this->url,
            'escalated' => $this->level > 0];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->level > 0 ? '[Escalated] ' : '').$this->title)->line($this->message);

        return $this->url ? $mail->action('Open', url($this->url)) : $mail;
    }
}
