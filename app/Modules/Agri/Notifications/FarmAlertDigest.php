<?php

namespace App\Modules\Agri\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The new farm alerts somebody should act on, in one email per scan. */
class FarmAlertDigest extends Notification
{
    use Queueable;

    /** @param list<array{farm: string, severity: string, title: string, message: ?string, link: ?string}> $alerts */
    public function __construct(public readonly string $organisation, public readonly array $alerts) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $critical = count(array_filter($this->alerts, fn ($a) => $a['severity'] === 'critical'));
        $mail = (new MailMessage)
            ->subject(($critical ? "[{$critical} critical] " : '').count($this->alerts).' new farm alert'.(count($this->alerts) === 1 ? '' : 's')." — {$this->organisation}")
            ->greeting('Farm alerts');
        foreach (array_slice($this->alerts, 0, 25) as $a) {
            $mail->line(($a['severity'] === 'critical' ? '🔴 ' : '🟠 ')."**{$a['title']}** ({$a['farm']})".($a['message'] ? " — {$a['message']}" : ''));
        }
        if (count($this->alerts) > 25) {
            $mail->line('…and '.(count($this->alerts) - 25).' more.');
        }

        return \App\Modules\Agri\Support\AgriMail::brand($mail->action('Open farm alerts', url('/agri/alerts'))
            ->line('You get these because you manage or supervise these farms, or reach every farm. Alert emails can be turned off per person on the farm\'s People & access tab.'), $notifiable->company_id ?? null);
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => count($this->alerts).' new farm alerts', 'message' => $this->alerts[0]['title'] ?? '', 'url' => '/agri/alerts'];
    }
}
