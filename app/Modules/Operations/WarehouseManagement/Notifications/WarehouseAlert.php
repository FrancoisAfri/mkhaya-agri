<?php

namespace App\Modules\Operations\WarehouseManagement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One warehouse alert, sent on the channels the rule names, one channel at a time so each is recorded (WMS-190). */
class WarehouseAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly string $event, public readonly string $title, public readonly string $message, public readonly ?string $url,
        public readonly array $channels, public readonly bool $escalation = false) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'wms_'.strtolower($this->event), 'title' => ($this->escalation ? 'Escalated: ' : '').$this->title, 'message' => $this->message, 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->escalation ? 'Escalated: ' : '').$this->title)->line($this->message);

        return $this->url ? $mail->action('Open in the warehouse', url($this->url)) : $mail;
    }
}
