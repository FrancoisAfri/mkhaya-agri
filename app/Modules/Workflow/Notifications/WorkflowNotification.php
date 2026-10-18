<?php

namespace App\Modules\Workflow\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every workflow notification (PRS §11): the bell, and email when the company allows it. The text
 * never carries more than the recipient may see; confidential workflows send a link only.
 */
class WorkflowNotification extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $url,
        /** @var list<string> */
        private readonly array $channels = ['database'],
    ) {
    }

    public function via(object $notifiable): array
    {
        return array_values(array_filter($this->channels, fn ($c) => $c !== 'mail' || filled($notifiable->email ?? null)));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'workflow.' . $this->kind, 'title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->message);

        return $this->url ? $mail->action('Open in Mkhaya Agri', $this->url) : $mail;
    }
}
