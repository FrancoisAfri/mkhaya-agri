<?php

namespace App\Modules\Agri\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A shift published, changed, cancelled or given to you; a work order assigned to you. */
class FarmWorkNotice extends Notification
{
    use Queueable;

    public function __construct(public readonly string $subject, public readonly string $text, public readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->text)->action('Open my work', url($this->url));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->subject, 'message' => $this->text, 'url' => $this->url];
    }
}
