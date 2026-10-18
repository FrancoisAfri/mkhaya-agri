<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaCheckinNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // type: 'scheduled' | 'reminder_day_before' | 'reminder_day_of'
    public function __construct(
        public readonly PaCheckin $checkin,
        public readonly string $type = 'scheduled',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->checkin->title ?? 'Check-In';
        $date  = $this->checkin->scheduled_at?->format('d M Y H:i');
        $mail  = (new MailMessage)->greeting('Hello,');

        return match ($this->type) {
            'scheduled' => $mail
                ->subject("Check-In Scheduled — {$title}")
                ->line("A check-in has been scheduled for you.")
                ->line("**{$title}**")
                ->line("**Date:** {$date}")
                ->when($this->checkin->location, fn($m) => $m->line("**Location:** {$this->checkin->location}"))
                ->when($this->checkin->agenda,   fn($m) => $m->line("**Agenda:** {$this->checkin->agenda}"))
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'reminder_day_before' => $mail
                ->subject("Reminder: Check-In Tomorrow — {$title}")
                ->line("Your check-in is scheduled for **tomorrow**.")
                ->line("**{$title}**")
                ->line("**Date:** {$date}")
                ->when($this->checkin->location, fn($m) => $m->line("**Location:** {$this->checkin->location}"))
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'reminder_day_of' => $mail
                ->subject("Reminder: Check-In Today — {$title}")
                ->line("You have a check-in **today**.")
                ->line("**{$title}**")
                ->line("**Date:** {$date}")
                ->when($this->checkin->location, fn($m) => $m->line("**Location:** {$this->checkin->location}"))
                ->action('View My Dashboard', route('performance.my-dashboard')),

            default => $mail->subject("Check-In Update — {$title}")->line("Your check-in has been updated."),
        };
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->checkin->title ?? 'Check-In';
        $date  = $this->checkin->scheduled_at?->format('d M Y H:i');

        $messages = [
            'scheduled'           => "Check-in scheduled: {$title} on {$date}.",
            'reminder_day_before' => "Reminder: Your check-in \"{$title}\" is tomorrow at {$date}.",
            'reminder_day_of'     => "Reminder: Your check-in \"{$title}\" is today at {$date}.",
        ];

        return [
            'type'       => "pa_checkin_{$this->type}",
            'message'    => $messages[$this->type] ?? "Check-in update: {$title}.",
            'checkin_id' => $this->checkin->id,
            'url'        => route('performance.my-dashboard'),
        ];
    }
}
