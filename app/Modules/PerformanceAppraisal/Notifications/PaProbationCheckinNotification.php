<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaProbationCheckin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaProbationCheckinNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // type: 'scheduled' | 'reminder_day_before' | 'reminder_day_of'
    public function __construct(
        public readonly PaProbationCheckin $checkin,
        public readonly string $type = 'scheduled',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date  = $this->checkin->scheduled_at?->format('d M Y H:i');
        $label = ucfirst($this->checkin->checkin_type ?? 'formal') . ' Check-In';
        $mail  = (new MailMessage)->greeting('Hello,');

        return match ($this->type) {
            'scheduled' => $mail
                ->subject("Probation Check-In Scheduled — {$label}")
                ->line("A probation check-in has been scheduled for you.")
                ->line("**Type:** {$label}")
                ->line("**Date:** {$date}")
                ->when($this->checkin->agenda, fn($m) => $m->line("**Agenda:** {$this->checkin->agenda}"))
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'reminder_day_before' => $mail
                ->subject("Reminder: Probation Check-In Tomorrow — {$label}")
                ->line("Your probation check-in is **tomorrow**.")
                ->line("**{$label}** — {$date}")
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'reminder_day_of' => $mail
                ->subject("Reminder: Probation Check-In Today — {$label}")
                ->line("You have a probation check-in **today**.")
                ->line("**{$label}** — {$date}")
                ->action('View My Dashboard', route('performance.my-dashboard')),

            default => $mail->subject("Probation Check-In Update")->line("Your probation check-in has been updated."),
        };
    }

    public function toArray(object $notifiable): array
    {
        $date  = $this->checkin->scheduled_at?->format('d M Y H:i');
        $label = ucfirst($this->checkin->checkin_type ?? 'formal') . ' Check-In';

        $messages = [
            'scheduled'           => "Probation check-in scheduled: {$label} on {$date}.",
            'reminder_day_before' => "Reminder: Your probation check-in ({$label}) is tomorrow at {$date}.",
            'reminder_day_of'     => "Reminder: Your probation check-in ({$label}) is today at {$date}.",
        ];

        return [
            'type'       => "pa_probation_checkin_{$this->type}",
            'message'    => $messages[$this->type] ?? "Probation check-in update.",
            'checkin_id' => $this->checkin->id,
            'review_id'  => $this->checkin->review_id,
            'url'        => route('performance.my-dashboard'),
        ];
    }
}
