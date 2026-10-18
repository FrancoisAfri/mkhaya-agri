<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\Pa360Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Pa360ReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // type: 'review_created' | 'added_as_rater'
    public function __construct(
        public readonly Pa360Review $review,
        public readonly string $type = 'review_created',
        public readonly string $relationship = 'peer',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->review->title;
        $due   = $this->review->due_date?->format('d M Y');
        $mail  = (new MailMessage)->greeting('Hello,');

        return match ($this->type) {
            'review_created' => $mail
                ->subject("360° Review Created — {$title}")
                ->line("A 360° review has been set up for your performance appraisal.")
                ->line("**{$title}**")
                ->when($due, fn($m) => $m->line("**Due:** {$due}"))
                ->when($this->review->instructions, fn($m) => $m->line($this->review->instructions))
                ->action('View My Dashboard', route('performance.my-dashboard')),

            'added_as_rater' => $mail
                ->subject("You've Been Invited to Provide 360° Feedback — {$title}")
                ->line("You have been invited to provide feedback as part of a 360° review.")
                ->line("**{$title}**")
                ->line("**Your role:** {$this->relationship}")
                ->when($due, fn($m) => $m->line("**Due:** {$due}"))
                ->when($this->review->instructions, fn($m) => $m->line($this->review->instructions))
                ->action('View 360 Reviews', route('performance.360.index')),

            default => $mail->subject("360° Review Update")->line("A 360° review has been updated."),
        };
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->review->title;
        $due   = $this->review->due_date?->format('d M Y');

        $messages = [
            'review_created' => "A 360° review has been created for you: \"{$title}\"" . ($due ? " — due {$due}." : '.'),
            'added_as_rater' => "You have been invited to provide 360° feedback for \"{$title}\"" . ($due ? " — due {$due}." : '.'),
        ];

        return [
            'type'      => "pa_360_{$this->type}",
            'message'   => $messages[$this->type] ?? "360° review update: {$title}.",
            'review_id' => $this->review->id,
            'url'       => $this->type === 'added_as_rater'
                ? route('performance.360.index')
                : route('performance.my-dashboard'),
        ];
    }
}
