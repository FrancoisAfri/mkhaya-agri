<?php

namespace App\Modules\HR\Leave\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Weekly (and repeating-until-resolved) alert to HR listing open leave anomalies.
 */
class LeaveAnomaliesDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param int   $total   number of open anomalies
     * @param array $bySeverity ['critical'=>n,'high'=>n,'medium'=>n,'low'=>n]
     * @param array $samples up to a few "Employee — Anomaly" lines
     */
    public function __construct(
        public readonly int $total,
        public readonly array $bySeverity,
        public readonly array $samples,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $high = ($this->bySeverity['critical'] ?? 0) + ($this->bySeverity['high'] ?? 0);

        $mail = (new MailMessage)
            ->subject("Leave anomalies need attention — {$this->total} open")
            ->greeting("Hello {$notifiable->name},")
            ->line("There are **{$this->total} open leave anomaly(ies)** for your company that will keep some employees' leave from running correctly.")
            ->line("**High/critical:** {$high} · **Medium:** " . ($this->bySeverity['medium'] ?? 0) . ' · **Low:** ' . ($this->bySeverity['low'] ?? 0));

        foreach (array_slice($this->samples, 0, 8) as $s) {
            $mail->line("• {$s}");
        }

        return $mail
            ->action('Review & fix', url('/leave-management/anomalies'))
            ->line('You will keep receiving this reminder until the anomalies are resolved or dismissed.')
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'        => 'leave_anomalies_digest',
            'total'       => $this->total,
            'by_severity' => $this->bySeverity,
            'message'     => "{$this->total} open leave anomaly(ies) need attention.",
            'url'         => '/leave-management/anomalies',
        ];
    }
}
