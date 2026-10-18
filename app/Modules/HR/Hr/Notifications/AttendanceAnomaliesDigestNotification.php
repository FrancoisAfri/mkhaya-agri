<?php

namespace App\Modules\HR\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Weekly (repeating-until-resolved) alert to HR listing open attendance anomalies.
 */
class AttendanceAnomaliesDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param int   $total      number of open anomalies
     * @param array $bySeverity ['critical'=>n,'high'=>n,'medium'=>n,'low'=>n]
     * @param array $samples    up to a few "Employee — Anomaly" lines
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
            ->subject("Attendance anomalies need attention — {$this->total} open")
            ->greeting("Hello {$notifiable->name},")
            ->line("There are **{$this->total} open attendance anomaly(ies)** for your company (unauthorised absences, lateness, clock-out issues and more).")
            ->line("**High/critical:** {$high} · **Medium:** " . ($this->bySeverity['medium'] ?? 0) . ' · **Low:** ' . ($this->bySeverity['low'] ?? 0));

        foreach (array_slice($this->samples, 0, 8) as $s) {
            $mail->line("• {$s}");
        }

        return $mail
            ->action('Review anomalies', url('/attendance/intelligence/anomalies'))
            ->line('You will keep receiving this reminder until the anomalies are resolved.')
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'        => 'attendance_anomalies_digest',
            'total'       => $this->total,
            'by_severity' => $this->bySeverity,
            'message'     => "{$this->total} open attendance anomaly(ies) need attention.",
            'url'         => '/attendance/intelligence/anomalies',
        ];
    }
}
