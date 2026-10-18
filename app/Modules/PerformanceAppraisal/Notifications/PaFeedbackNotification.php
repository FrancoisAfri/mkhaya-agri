<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaFeedbackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaFeedback $feedback,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = ucfirst(str_replace('_', ' ', $this->feedback->feedback_type));
        $mail = (new MailMessage)->greeting('Hello,');

        if ($this->feedback->is_anonymous) {
            return $mail
                ->subject("New Anonymous Feedback Received")
                ->line("You have received new anonymous {$type} feedback.")
                ->when($this->feedback->rating, fn($m) => $m->line("**Rating:** {$this->feedback->rating}/5"))
                ->action('View My Dashboard', route('performance.my-dashboard'));
        }

        $author = $this->resolvedAuthorName();

        return $mail
            ->subject("New Feedback Received from {$author}")
            ->line("**{$author}** has submitted {$type} feedback for you.")
            ->when($this->feedback->rating, fn($m) => $m->line("**Rating:** {$this->feedback->rating}/5"))
            ->action('View My Dashboard', route('performance.my-dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        $type = ucfirst(str_replace('_', ' ', $this->feedback->feedback_type));

        if ($this->feedback->is_anonymous) {
            $message = "You received new anonymous {$type} feedback.";
        } else {
            $author  = $this->resolvedAuthorName();
            $message = "{$author} submitted {$type} feedback for you.";
        }

        return [
            'type'        => 'pa_feedback_received',
            'message'     => $message,
            'feedback_id' => $this->feedback->id,
            // Never expose given_by in the payload when anonymous
            'author'      => $this->feedback->is_anonymous ? null : $this->feedback->given_by,
            'url'         => route('performance.my-dashboard'),
        ];
    }

    private function resolvedAuthorName(): string
    {
        $person = $this->feedback->author?->person;
        if ($person) {
            return trim("{$person->first_name} {$person->surname}") ?: $this->feedback->author->email;
        }

        return $this->feedback->author?->email ?? 'Someone';
    }
}
