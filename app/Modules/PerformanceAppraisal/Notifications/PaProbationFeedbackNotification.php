<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaProbationFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaProbationFeedbackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaProbationFeedback $feedback,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function authorName(): string
    {
        $author = $this->feedback->author?->person;
        if ($author) {
            $name = trim(($author->first_name ?? '') . ' ' . ($author->surname ?? ''));
            if ($name) return $name;
        }
        return $this->feedback->author?->email ?? 'Someone';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type   = ucfirst($this->feedback->feedback_type ?? 'manager');
        $author = $this->authorName();

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("New Probation Feedback — {$type}")
            ->line("{$author} has submitted feedback on your probation review.")
            ->line("**Type:** {$type} Feedback")
            ->when($this->feedback->rating, fn($m) => $m->line("**Rating:** {$this->feedback->rating}/5"))
            ->action('View My Dashboard', route('performance.my-dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        $type   = ucfirst($this->feedback->feedback_type ?? 'manager');
        $author = $this->authorName();

        return [
            'type'        => 'pa_probation_feedback',
            'message'     => "{$author} submitted {$type} feedback on your probation.",
            'feedback_id' => $this->feedback->id,
            'review_id'   => $this->feedback->review_id,
            'url'         => route('performance.my-dashboard'),
        ];
    }
}
