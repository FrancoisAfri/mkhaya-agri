<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaCoachingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaCoachingSessionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // type: 'scheduled' | 'reminder_day_before' | 'reminder_day_of'
    public function __construct(
        public readonly PaCoachingSession $session,
        public readonly string $type = 'scheduled',
        public readonly string $role = 'employee', // 'employee' | 'coach'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->session->title ?? 'Coaching Session';
        $date  = $this->session->session_date?->format('d M Y');
        $mail  = (new MailMessage)->greeting('Hello,');

        $roleLabel = $this->role === 'coach' ? 'as the coach' : '';

        return match ($this->type) {
            'scheduled' => $mail
                ->subject("Coaching Session Scheduled — {$title}")
                ->line("A coaching session has been scheduled {$roleLabel}.")
                ->line("**{$title}**")
                ->line("**Date:** {$date}")
                ->when($this->session->objectives, fn($m) => $m->line("**Objectives:** {$this->session->objectives}"))
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'reminder_day_before' => $mail
                ->subject("Reminder: Coaching Session Tomorrow — {$title}")
                ->line("Your coaching session is scheduled for **tomorrow**.")
                ->line("**{$title}**")
                ->line("**Date:** {$date}")
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'reminder_day_of' => $mail
                ->subject("Reminder: Coaching Session Today — {$title}")
                ->line("You have a coaching session **today**.")
                ->line("**{$title}**")
                ->line("**Date:** {$date}")
                ->action('View My Dashboard', route('performance.my-dashboard')),

            default => $mail->subject("Coaching Session Update — {$title}")->line("Your coaching session has been updated."),
        };
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->session->title ?? 'Coaching Session';
        $date  = $this->session->session_date?->format('d M Y');

        $messages = [
            'scheduled'           => "Coaching session scheduled: {$title} on {$date}.",
            'reminder_day_before' => "Reminder: Your coaching session \"{$title}\" is tomorrow ({$date}).",
            'reminder_day_of'     => "Reminder: Your coaching session \"{$title}\" is today ({$date}).",
        ];

        return [
            'type'       => "pa_coaching_{$this->type}",
            'message'    => $messages[$this->type] ?? "Coaching session update: {$title}.",
            'session_id' => $this->session->id,
            'url'        => route('performance.my-dashboard'),
        ];
    }
}
