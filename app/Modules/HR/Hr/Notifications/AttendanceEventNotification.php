<?php

namespace App\Modules\HR\Hr\Notifications;

use App\Modules\HR\Hr\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string  $event,
        public readonly string  $title,
        public readonly string  $message,
        public readonly ?string $url = null,
        public readonly string  $channel = 'email',   // email | sms | whatsapp | all
    ) {}

    public function via(mixed $notifiable): array
    {
        $channels = ['database'];

        if (in_array($this->channel, ['email', 'all'])) {
            $channels[] = 'mail';
        }

        if (in_array($this->channel, ['whatsapp', 'all'])) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message);

        if ($this->url) {
            $mail->action('View Details', url($this->url));
        }

        return $mail->salutation('Mkhaya HR System');
    }

    public function toWhatsApp(mixed $notifiable): string
    {
        $body = "*{$this->title}*\n{$this->message}";

        if ($this->url) {
            $body .= "\n" . url($this->url);
        }

        return $body;
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'    => 'attendance_' . $this->event,
            'title'   => $this->title,
            'message' => $this->message,
            'url'     => $this->url,
        ];
    }
}
