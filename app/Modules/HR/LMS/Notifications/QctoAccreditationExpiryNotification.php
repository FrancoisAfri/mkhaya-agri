<?php

namespace App\Modules\HR\LMS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QctoAccreditationExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected array $alerts) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->alerts);
        $mail  = (new MailMessage)
            ->subject("QCTO Accreditation Alert: {$count} item(s) expiring soon")
            ->greeting("Hello {$notifiable->name},")
            ->line("The following {$count} QCTO accreditation(s) are expiring within 90 days and require attention:");

        foreach ($this->alerts as $alert) {
            $mail->line("• **{$alert['type']}** — {$alert['entity']} (Ref: {$alert['reference']}) expires **{$alert['expires_at']}** ({$alert['days_left']} days left).");
        }

        return $mail
            ->action('View LMS', url(route('lms.admin.qcto.dashboard')))
            ->line('Please renew or arrange renewal before the expiry date to maintain SETA accreditation status.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'qcto_accreditation_expiry',
            'alert_count'=> count($this->alerts),
            'alerts'     => $this->alerts,
            'message'    => count($this->alerts) . ' QCTO accreditation(s) expiring within 90 days.',
        ];
    }
}
